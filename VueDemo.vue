<template>
<div>
  <TestDemo
    :is=""
    v-for=""
    v-if=""
    v-else-if=""
    v-else=""

    v-show=""
    v-cloak=""
    v-pre=""
    v-once=""

    id=""
    ref=""
    key=""
    slot=""

    v-model=""
    v-on=""
    v-html=""
    v-text=""
  >
    {{ test }}
  </TestDemo>
</div>
</template>

<script type="text/babel">
import { mapGetters, mapActions } from 'vuex';

/**
 * ----------------------------------------------------------------------------------
 *  VueDemo Component
 * ----------------------------------------------------------------------------------
 *
 * @link https://cn.vuejs.org/v2/api
 * @link https://cn.vuejs.org/v2/style-guide/index.html#%E4%BC%98%E5%85%88%E7%BA%A7-C-%E7%9A%84%E8%A7%84%E5%88%99%EF%BC%9A%E6%8E%A8%E8%8D%90-%E5%B0%86%E9%80%89%E6%8B%A9%E5%92%8C%E8%AE%A4%E7%9F%A5%E6%88%90%E6%9C%AC%E6%9C%80%E5%B0%8F%E5%8C%96
 * @author lanlin
 * @change 2018/12/07
 */
export default {
  el: null,
  name: 'VueDemo',
  parent: null,
  functional: false,
  delimiters: ['${', '}'],

  comments: false,
  components: {},
  directives: {},
  filters: {},

  extends: null,
  mixins: [],
  inheritAttrs: true,

  model: {
    prop: 'checked',
    event: 'change',
  },

  props: {
    // 将 value 属性用作其他目的
    value: {
      type: String,
      default: null,
    },

    // 用 checked 替代 value 的位置
    checked: {
      type: Number,
      default: 0,
    },

    age: {
      type: Number,
      default: 0,
      required: true,
      validator(value) { return value >= 0; },
    },
  },

  propsData: {},

  data() {
    return {
      test: '',
    };
  },

  computed: {
    ...mapGetters([
      // ...
    ]),

    // 仅读取
    aDouble() { return this.a * 2; },

    // 读取和设置
    aPlus: {
      get() { return this.a + 1; },
      set(v) { this.a = v - 1; },
    },
  },

  watch: {},

  beforeCreate() {},
  created() {},

  beforeMount() {},
  mounted() {},

  beforeUpdate() {},
  updated() {},

  activated() {},
  deactivated() {},

  beforeDestroy() {},
  destroyed() {},

  methods: {

    // ------------------------------------------------------------------------------

    // map action methods
    ...mapActions([
      // ...
    ]),

    // ------------------------------------------------------------------------------

    testing() {
      // ...
    },

    // ------------------------------------------------------------------------------

  },

  template: '',

  render(h) {
    return h(
      // ...
    );
  },

  renderError(h, err) {
    return h('pre', { style: { color: 'red' } }, err.stack);
  },
};
</script>

<style lang="less">
@import '~src/assets/style/component.less';
</style>
