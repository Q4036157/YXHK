import json
import tempfile
import threading
import unittest
from http.server import ThreadingHTTPServer
from urllib.error import HTTPError
from urllib.parse import urlencode
from urllib.request import Request, urlopen
from unsubscribe_server import Store, make_handler


class UnsubscribeTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.store = Store(self.temp.name+'/db.sqlite')
        self.config = {'public_url':'https://example.com','tenants':{'a':'a'*48,'b':'b'*48}}
        self.server = ThreadingHTTPServer(('127.0.0.1',0),make_handler(self.store,self.config))
        self.thread = threading.Thread(target=self.server.serve_forever,daemon=True)
        self.thread.start()
        self.base = 'http://127.0.0.1:'+str(self.server.server_port)

    def tearDown(self):
        self.server.shutdown()
        self.server.server_close()
        self.temp.cleanup()

    def request(self,path,body=None,key='a'*48,form=False):
        data = (urlencode(body) if form else json.dumps(body)).encode() if body is not None else None
        req = Request(self.base+path,data=data,headers={'Authorization':'Bearer '+key,'Content-Type':'application/x-www-form-urlencoded' if form else 'application/json'})
        with urlopen(req) as response:
            return response.read().decode()

    def test_confirmation_one_click_and_tenant_isolation(self):
        first=json.loads(self.request('/internal/prepare',{'email':'Person@Example.com'}))
        self.assertFalse(first['blocked'])
        token=first['url'].split('token=')[1]
        self.request('/marketing/unsubscribe?token='+token)
        self.assertFalse(self.store.blocked('a','person@example.com'))
        self.request('/marketing/unsubscribe',{'token':token},form=True)
        self.request('/marketing/unsubscribe?token='+token,{'List-Unsubscribe':'One-Click'},form=True)
        self.assertTrue(json.loads(self.request('/internal/check',{'email':'PERSON@example.com'}))['blocked'])
        self.assertFalse(json.loads(self.request('/internal/check',{'email':'person@example.com'},key='b'*48))['blocked'])
        events=json.loads(self.request('/internal/events?after=0'))
        self.assertEqual(len(events['events']),1)
        self.assertEqual(len(json.loads(self.request('/internal/events?after='+str(events['cursor'])))['events']),0)
        reopened=Store(self.store.path)
        self.assertTrue(reopened.blocked('a','person@example.com'))

    def test_unauthorized_invalid_link_and_invalid_email(self):
        for path,body,key in [('/internal/check',{'email':'test@example.com'},'wrong'),('/marketing/unsubscribe?token=wrong',None,'a'*48),('/internal/prepare',{'email':'bad'},'a'*48)]:
            with self.assertRaises(HTTPError):
                self.request(path,body,key)
        self.assertEqual(self.store.events('a',0),[])


if __name__ == '__main__':
    unittest.main()
