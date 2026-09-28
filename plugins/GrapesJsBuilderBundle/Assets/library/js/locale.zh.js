import zh from 'grapesjs/locale/zh';

// Fill the remaining English labels in the upstream Chinese editor locale.
zh.domComponents.names[''] = '容器';
zh.domComponents.names.wrapper = '页面主体';
zh.deviceManager.devices.mobileLandscape = '手机横屏';
zh.deviceManager.devices.mobilePortrait = '手机竖屏';
zh.selectorManager.label = '样式类';
zh.selectorManager.selected = '已选中';
zh.selectorManager.emptyState = '选择状态';
zh.selectorManager.states.hover = '悬停';
zh.selectorManager.states.active = '点击';
zh.selectorManager.states['nth-of-type(2n)'] = '奇数／偶数';
zh.traitManager.traits.attributes.href.placeholder = '例如 https://example.com';
zh.styleManager.empty = '请选择元素，再设置样式';
zh.traitManager.empty = '请选择组件，再设置属性';
zh.panels.buttons.titles['open-blocks'] = '打开组件库';
zh.blockManager = {
  labels: {
    text: '文本', link: '链接', 'link-block': '链接区块', image: '图片', video: '视频',
    map: '地图', quote: '引用', button: '按钮', divider: '分隔线', section: '区块',
    'column1': '单列', 'column2': '双列', 'column3': '三列', 'column3-7': '三七分栏',
    'text-basic': '基础文本', 'text-section': '文本区块', 'grid-items': '网格列表',
    'list-items': '列表', countdown: '倒计时', navbar: '导航栏', 'custom-code': '自定义代码',
    form: '表单', input: '输入框', textarea: '多行文本', select: '下拉选择',
    checkbox: '复选框', radio: '单选框', label: '标签', table: '表格',
    'mj-section': '区块', 'mj-column': '列', 'mj-text': '文本', 'mj-image': '图片',
    'mj-button': '按钮', 'mj-divider': '分隔线', 'mj-spacer': '间隔', 'mj-social': '社交链接',
    'mj-hero': '主视觉区块', 'mj-wrapper': '容器', 'mj-navbar': '导航栏', 'mj-raw': '原始代码',
    'mj-table': '表格', 'mj-group': '分组', 'mj-accordion': '折叠面板',
  },
};
zh.traitManager.traits.labels = {
  id: 'ID', title: '标题', alt: '替代文本', href: '链接地址', target: '打开方式',
  src: '资源地址', name: '名称', type: '类型', value: '值', placeholder: '提示文本',
  required: '必填', checked: '选中', method: '请求方式', action: '提交地址',
  width: '宽度', height: '高度', autoplay: '自动播放', loop: '循环播放', controls: '播放控件',
  poster: '封面', muted: '静音', provider: '提供商', address: '地址', zoom: '缩放',
};
zh.styleManager.properties = {
  display: '显示方式', position: '定位方式', float: '浮动', top: '顶部', right: '右侧',
  bottom: '底部', left: '左侧', width: '宽度', height: '高度', 'max-width': '最大宽度',
  'min-width': '最小宽度', 'max-height': '最大高度', 'min-height': '最小高度',
  margin: '外边距', 'margin-top': '上外边距', 'margin-right': '右外边距',
  'margin-bottom': '下外边距', 'margin-left': '左外边距', padding: '内边距',
  'padding-top': '上内边距', 'padding-right': '右内边距', 'padding-bottom': '下内边距',
  'padding-left': '左内边距', color: '文字颜色', 'font-family': '字体', 'font-size': '字号',
  'font-weight': '字体粗细', 'font-style': '字体样式', 'letter-spacing': '字间距',
  'line-height': '行高', 'text-align': '文本对齐', 'text-decoration': '文本装饰',
  'text-shadow': '文字阴影', 'text-transform': '文本大小写', 'white-space': '空白处理',
  background: '背景', 'background-color': '背景颜色', 'background-image': '背景图片',
  'background-size': '背景尺寸', 'background-position': '背景位置',
  'background-repeat': '背景重复', 'background-attachment': '背景滚动',
  border: '边框', 'border-width': '边框宽度', 'border-style': '边框样式',
  'border-color': '边框颜色', 'border-radius': '圆角', 'border-collapse': '合并边框',
  'box-shadow': '盒子阴影', opacity: '透明度', overflow: '溢出处理', cursor: '鼠标样式',
  'z-index': '层叠顺序', transition: '过渡', transform: '变换', 'transform-origin': '变换原点',
  'flex-direction': '排列方向', 'flex-wrap': '换行', 'justify-content': '主轴对齐',
  'align-items': '交叉轴对齐', 'align-content': '多行对齐', 'align-self': '自身对齐',
  'flex-grow': '放大比例', 'flex-shrink': '缩小比例', 'flex-basis': '基础尺寸',
  order: '顺序', gap: '间距', 'box-sizing': '盒子尺寸计算', 'vertical-align': '垂直对齐',
};
const cssOptions = {
  none: '无', auto: '自动', normal: '正常', inherit: '继承', initial: '初始值', unset: '取消设置',
  block: '块级', inline: '行内', 'inline-block': '行内块', flex: '弹性布局', grid: '网格布局',
  static: '默认定位', relative: '相对定位', absolute: '绝对定位', fixed: '固定定位', sticky: '粘性定位',
  left: '左侧', right: '右侧', center: '居中', top: '顶部', bottom: '底部', justify: '两端对齐',
  solid: '实线', dashed: '虚线', dotted: '点线', double: '双线', groove: '凹槽', ridge: '凸起',
  inset: '内凹', outset: '外凸', bold: '粗体', italic: '斜体', oblique: '倾斜',
  underline: '下划线', 'line-through': '删除线', overline: '上划线',
  uppercase: '大写', lowercase: '小写', capitalize: '首字母大写',
  visible: '可见', hidden: '隐藏', scroll: '滚动', pointer: '指针', default: '默认',
  cover: '铺满', contain: '完整显示', repeat: '重复', 'no-repeat': '不重复',
  'repeat-x': '横向重复', 'repeat-y': '纵向重复', row: '横向', column: '纵向',
  'row-reverse': '横向反转', 'column-reverse': '纵向反转', wrap: '换行', nowrap: '不换行',
  'wrap-reverse': '反向换行', stretch: '拉伸', baseline: '基线',
  'flex-start': '起始端', 'flex-end': '末端', 'space-between': '两端分布',
  'space-around': '均匀分布', 'space-evenly': '等距分布',
  'border-box': '包含边框', 'content-box': '内容尺寸',
};
zh.styleManager.options = Object.fromEntries(
  Object.keys(zh.styleManager.properties).map(property => [property, cssOptions]),
);

export default {
  locale: 'zh',
  localeFallback: 'zh',
  detectLocale: false,
  messages: { zh },
};
