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

export default {
  locale: 'zh',
  localeFallback: 'zh',
  detectLocale: false,
  messages: { zh },
};
