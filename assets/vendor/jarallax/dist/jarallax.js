/*!
 * Name    : Just Another Parallax [Jarallax]
 * Version : 1.12.4
 * Author  : nK <https://nkdev.info>
 * GitHub  : https://github.com/nk-o/jarallax
 */
/******/
(function(modules) { // webpackBootstrap
    /******/ // The module cache
    /******/
    const installedModules = {};
    /******/
    /******/ // The require function
    /******/
    function __webpack_require__(moduleId) {
        /******/
        /******/ // Check if module is in cache
        /******/
        if (installedModules[moduleId]) {
            /******/
            return installedModules[moduleId].exports;
            /******/
        }
        /******/ // Create a new module (and put it into the cache)
        /******/
        const module = installedModules[moduleId] = {
            /******/
            i: moduleId,
            /******/
            l: false,
            /******/
            exports: {},
            /******/
        };
        /******/
        /******/ // Execute the module function
        /******/
        modules[moduleId].call(module.exports, module, module.exports, __webpack_require__);
        /******/
        /******/ // Flag the module as loaded
        /******/
        module.l = true;
        /******/
        /******/ // Return the exports of the module
        /******/
        return module.exports;
        /******/
    }
    /******/
    /******/
    /******/ // expose the modules object (__webpack_modules__)
    /******/
    __webpack_require__.m = modules;
    /******/
    /******/ // expose the module cache
    /******/
    __webpack_require__.c = installedModules;
    /******/
    /******/ // define getter function for harmony exports
    /******/
    __webpack_require__.d = function(exports, name, getter) {
        /******/
        if (!__webpack_require__.o(exports, name)) {
            /******/
            Object.defineProperty(exports, name, {
                enumerable: true,
                get: getter,
            });
            /******/
        }
        /******/
    };
    /******/
    /******/ // define __esModule on exports
    /******/
    __webpack_require__.r = function(exports) {
        /******/
        if (typeof Symbol !== 'undefined' && Symbol.toStringTag) {
            /******/
            Object.defineProperty(exports, Symbol.toStringTag, {
                value: 'Module',
            });
            /******/
        }
        /******/
        Object.defineProperty(exports, '__esModule', {
            value: true,
        });
        /******/
    };
    /******/
    /******/ // create a fake namespace object
    /******/ // mode & 1: value is a module id, require it
    /******/ // mode & 2: merge all properties of value into the ns
    /******/ // mode & 4: return value when already ns object
    /******/ // mode & 8|1: behave like require
    /******/
    __webpack_require__.t = function(value, mode) {
        /******/
        if (mode & 1) {value = __webpack_require__(value);}
        /******/
        if (mode & 8) {return value;}
        /******/
        if ((mode & 4) && typeof value === 'object' && value && value.__esModule) {return value;}
        /******/
        const ns = Object.create(null);
        /******/
        __webpack_require__.r(ns);
        /******/
        Object.defineProperty(ns, 'default', {
            enumerable: true,
            value: value,
        });
        /******/
        if (mode & 2 && typeof value !== 'string')
        {for (const key in value) {__webpack_require__.d(ns, key, function(key) {
            return value[key];
        }.bind(null, key));}}
        /******/
        return ns;
        /******/
    };
    /******/
    /******/ // getDefaultExport function for compatibility with non-harmony modules
    /******/
    __webpack_require__.n = function(module) {
        /******/
        const getter = module && module.__esModule ?
            /******/
            function getDefault() {
                return module['default'];
            } :
            /******/
            function getModuleExports() {
                return module;
            };
        /******/
        __webpack_require__.d(getter, 'a', getter);
        /******/
        return getter;
        /******/
    };
    /******/
    /******/ // Object.prototype.hasOwnProperty.call
    /******/
    __webpack_require__.o = function(object, property) {
        return Object.prototype.hasOwnProperty.call(object, property);
    };
    /******/
    /******/ // __webpack_public_path__
    /******/
    __webpack_require__.p = '';
    /******/
    /******/
    /******/ // Load entry module and return exports
    /******/
    return __webpack_require__(__webpack_require__.s = 10);
    /******/
})
/************************************************************************/
/******/
([
    /* 0 */
    ,
    /* 1 */
    ,
    /* 2 */
    /***/
    (function(module, exports) {

        module.exports = function(callback) {
            if (document.readyState === 'complete' || document.readyState === 'interactive') {
                // Already ready or interactive, execute callback
                callback.call();
            } else if (document.attachEvent) {
                // Old browsers
                document.attachEvent('onreadystatechange', function() {
                    if (document.readyState === 'interactive') {callback.call();}
                });
            } else if (document.addEventListener) {
                // Modern browsers
                document.addEventListener('DOMContentLoaded', callback);
            }
        };

        /***/
    }),
    /* 3 */
    /***/
    (function(module, exports, __webpack_require__) {

        /* WEBPACK VAR INJECTION */
        (function(global) {
            let win;

            if (typeof window !== 'undefined') {
                win = window;
            } else if (typeof global !== 'undefined') {
                win = global;
            } else if (typeof self !== 'undefined') {
                win = self;
            } else {
                win = {};
            }

            module.exports = win;
            /* WEBPACK VAR INJECTION */
        }.call(this, __webpack_require__(4)));

        /***/
    }),
    /* 4 */
    /***/
    (function(module, exports) {

        function _typeof(obj) {
            '@babel/helpers - typeof';
            if (typeof Symbol === 'function' && typeof Symbol.iterator === 'symbol') {
                _typeof = function _typeof(obj) {
                    return typeof obj;
                };
            } else {
                _typeof = function _typeof(obj) {
                    return obj && typeof Symbol === 'function' && obj.constructor === Symbol && obj !== Symbol.prototype ? 'symbol' : typeof obj;
                };
            }
            return _typeof(obj);
        }

        let g; // This works in non-strict mode

        g = function() {
            return this;
        }();

        try {
            // This works if eval is allowed (see CSP)
            g = g || new Function('return this')();
        } catch (e) {
            // This works if the window reference is available
            if ((typeof window === 'undefined' ? 'undefined' : _typeof(window)) === 'object') {g = window;}
        } // g can still be undefined, but nothing to do about it...
        // We return undefined, instead of nothing here, so it's
        // easier to handle this case. if(!global) { ...}


        module.exports = g;

        /***/
    }),
    /* 5 */
    ,
    /* 6 */
    ,
    /* 7 */
    ,
    /* 8 */
    ,
    /* 9 */
    ,
    /* 10 */
    /***/
    (function(module, exports, __webpack_require__) {

        module.exports = __webpack_require__(11);


        /***/
    }),
    /* 11 */
    /***/
    (function(module, __webpack_exports__, __webpack_require__) {

        'use strict';
        __webpack_require__.r(__webpack_exports__);
        /* harmony import */
        const lite_ready__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(2);
        /* harmony import */
        const lite_ready__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/ __webpack_require__.n(lite_ready__WEBPACK_IMPORTED_MODULE_0__);
        /* harmony import */
        const global__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(3);
        /* harmony import */
        const global__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/ __webpack_require__.n(global__WEBPACK_IMPORTED_MODULE_1__);
        /* harmony import */
        const _jarallax_esm__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(12);

        function _typeof(obj) {
            '@babel/helpers - typeof';
            if (typeof Symbol === 'function' && typeof Symbol.iterator === 'symbol') {
                _typeof = function _typeof(obj) {
                    return typeof obj;
                };
            } else {
                _typeof = function _typeof(obj) {
                    return obj && typeof Symbol === 'function' && obj.constructor === Symbol && obj !== Symbol.prototype ? 'symbol' : typeof obj;
                };
            }
            return _typeof(obj);
        }


        // no conflict

        const oldPlugin = global__WEBPACK_IMPORTED_MODULE_1__['window'].jarallax;
        global__WEBPACK_IMPORTED_MODULE_1__['window'].jarallax = _jarallax_esm__WEBPACK_IMPORTED_MODULE_2__['default'];

        global__WEBPACK_IMPORTED_MODULE_1__['window'].jarallax.noConflict = function() {
            global__WEBPACK_IMPORTED_MODULE_1__['window'].jarallax = oldPlugin;
            return this;
        }; // jQuery support


        if ('undefined' !== typeof global__WEBPACK_IMPORTED_MODULE_1__['jQuery']) {
            const jQueryPlugin = function jQueryPlugin() {
                for (var _len = arguments.length, args = new Array(_len), _key = 0; _key < _len; _key++) {
                    args[_key] = arguments[_key];
                }

                Array.prototype.unshift.call(args, this);
                const res = _jarallax_esm__WEBPACK_IMPORTED_MODULE_2__['default'].apply(global__WEBPACK_IMPORTED_MODULE_1__['window'], args);
                return 'object' !== _typeof(res) ? res : this;
            };

            jQueryPlugin.constructor = _jarallax_esm__WEBPACK_IMPORTED_MODULE_2__['default'].constructor; // no conflict

            const oldJqPlugin = global__WEBPACK_IMPORTED_MODULE_1__['jQuery'].fn.jarallax;
            global__WEBPACK_IMPORTED_MODULE_1__['jQuery'].fn.jarallax = jQueryPlugin;

            global__WEBPACK_IMPORTED_MODULE_1__['jQuery'].fn.jarallax.noConflict = function() {
                global__WEBPACK_IMPORTED_MODULE_1__['jQuery'].fn.jarallax = oldJqPlugin;
                return this;
            };
        } // data-jarallax initialization


        lite_ready__WEBPACK_IMPORTED_MODULE_0___default()(function() {
            Object(_jarallax_esm__WEBPACK_IMPORTED_MODULE_2__['default'])(document.querySelectorAll('[data-jarallax]'));
        });

        /***/
    }),
    /* 12 */
    /***/
    (function(module, __webpack_exports__, __webpack_require__) {

        'use strict';
        __webpack_require__.r(__webpack_exports__);
        /* harmony import */
        const lite_ready__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(2);
        /* harmony import */
        const lite_ready__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/ __webpack_require__.n(lite_ready__WEBPACK_IMPORTED_MODULE_0__);
        /* harmony import */
        const global__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(3);
        /* harmony import */
        const global__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/ __webpack_require__.n(global__WEBPACK_IMPORTED_MODULE_1__);

        function _slicedToArray(arr, i) {
            return _arrayWithHoles(arr) || _iterableToArrayLimit(arr, i) || _unsupportedIterableToArray(arr, i) || _nonIterableRest();
        }

        function _nonIterableRest() {
            throw new TypeError('Invalid attempt to destructure non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method.');
        }

        function _unsupportedIterableToArray(o, minLen) {
            if (!o) {return;}
            if (typeof o === 'string') {return _arrayLikeToArray(o, minLen);}
            let n = Object.prototype.toString.call(o).slice(8, -1);
            if (n === 'Object' && o.constructor) {n = o.constructor.name;}
            if (n === 'Map' || n === 'Set') {return Array.from(o);}
            if (n === 'Arguments' || /^(?:Ui|I)nt(?:8|16|32)(?:Clamped)?Array$/.test(n)) {return _arrayLikeToArray(o, minLen);}
        }

        function _arrayLikeToArray(arr, len) {
            if (len == null || len > arr.length) {len = arr.length;}
            for (var i = 0, arr2 = new Array(len); i < len; i++) {
                arr2[i] = arr[i];
            }
            return arr2;
        }

        function _iterableToArrayLimit(arr, i) {
            if (typeof Symbol === 'undefined' || !(Symbol.iterator in Object(arr))) {return;}
            const _arr = [];
            let _n = true;
            let _d = false;
            let _e = undefined;
            try {
                for (var _i = arr[Symbol.iterator](), _s; !(_n = (_s = _i.next()).done); _n = true) {
                    _arr.push(_s.value);
                    if (i && _arr.length === i) {break;}
                }
            } catch (err) {
                _d = true;
                _e = err;
            } finally {
                try {
                    if (!_n && _i['return'] != null) {_i['return']();}
                } finally {
                    if (_d) {throw _e;}
                }
            }
            return _arr;
        }

        function _arrayWithHoles(arr) {
            if (Array.isArray(arr)) {return arr;}
        }

        function _typeof(obj) {
            '@babel/helpers - typeof';
            if (typeof Symbol === 'function' && typeof Symbol.iterator === 'symbol') {
                _typeof = function _typeof(obj) {
                    return typeof obj;
                };
            } else {
                _typeof = function _typeof(obj) {
                    return obj && typeof Symbol === 'function' && obj.constructor === Symbol && obj !== Symbol.prototype ? 'symbol' : typeof obj;
                };
            }
            return _typeof(obj);
        }

        function _classCallCheck(instance, Constructor) {
            if (!(instance instanceof Constructor)) {
                throw new TypeError('Cannot call a class as a function');
            }
        }

        function _defineProperties(target, props) {
            for (let i = 0; i < props.length; i++) {
                const descriptor = props[i];
                descriptor.enumerable = descriptor.enumerable || false;
                descriptor.configurable = true;
                if ('value' in descriptor) {descriptor.writable = true;}
                Object.defineProperty(target, descriptor.key, descriptor);
            }
        }

        function _createClass(Constructor, protoProps, staticProps) {
            if (protoProps) {_defineProperties(Constructor.prototype, protoProps);}
            if (staticProps) {_defineProperties(Constructor, staticProps);}
            return Constructor;
        }


        const navigator = global__WEBPACK_IMPORTED_MODULE_1__['window'].navigator;
        const isIE = -1 < navigator.userAgent.indexOf('MSIE ') || -1 < navigator.userAgent.indexOf('Trident/') || -1 < navigator.userAgent.indexOf('Edge/');
        const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);

        const supportTransform = function() {
            const prefixes = 'transform WebkitTransform MozTransform'.split(' ');
            const div = document.createElement('div');

            for (let i = 0; i < prefixes.length; i += 1) {
                if (div && div.style[prefixes[i]] !== undefined) {
                    return prefixes[i];
                }
            }

            return false;
        }();

        let $deviceHelper;
        /**
         * The most popular mobile browsers changes height after page scroll and this generates image jumping.
         * We can fix it using this workaround with vh units.
         */

        function getDeviceHeight() {
            if (!$deviceHelper && document.body) {
                $deviceHelper = document.createElement('div');
                $deviceHelper.style.cssText = 'position: fixed; top: -9999px; left: 0; height: 100vh; width: 0;';
                document.body.appendChild($deviceHelper);
            }

            return ($deviceHelper ? $deviceHelper.clientHeight : 0) || global__WEBPACK_IMPORTED_MODULE_1__['window'].innerHeight || document.documentElement.clientHeight;
        } // Window height data


        let wndH;

        function updateWndVars() {
            if (isMobile) {
                wndH = getDeviceHeight();
            } else {
                wndH = global__WEBPACK_IMPORTED_MODULE_1__['window'].innerHeight || document.documentElement.clientHeight;
            }
        }

        updateWndVars();
        global__WEBPACK_IMPORTED_MODULE_1__['window'].addEventListener('resize', updateWndVars);
        global__WEBPACK_IMPORTED_MODULE_1__['window'].addEventListener('orientationchange', updateWndVars);
        global__WEBPACK_IMPORTED_MODULE_1__['window'].addEventListener('load', updateWndVars);
        lite_ready__WEBPACK_IMPORTED_MODULE_0___default()(function() {
            updateWndVars({
                type: 'dom-loaded',
            });
        }); // list with all jarallax instances
        // need to render all in one scroll/resize event

        const jarallaxList = []; // get all parents of the element.

        function getParents(elem) {
            const parents = [];

            while (null !== elem.parentElement) {
                elem = elem.parentElement;

                if (1 === elem.nodeType) {
                    parents.push(elem);
                }
            }

            return parents;
        }

        function updateParallax() {
            if (!jarallaxList.length) {
                return;
            }

            jarallaxList.forEach(function(data, k) {
                const instance = data.instance,
                    oldData = data.oldData;
                const clientRect = instance.$item.getBoundingClientRect();
                const newData = {
                    width: clientRect.width,
                    height: clientRect.height,
                    top: clientRect.top,
                    bottom: clientRect.bottom,
                    wndW: global__WEBPACK_IMPORTED_MODULE_1__['window'].innerWidth,
                    wndH: wndH,
                };
                const isResized = !oldData || oldData.wndW !== newData.wndW || oldData.wndH !== newData.wndH || oldData.width !== newData.width || oldData.height !== newData.height;
                const isScrolled = isResized || !oldData || oldData.top !== newData.top || oldData.bottom !== newData.bottom;
                jarallaxList[k].oldData = newData;

                if (isResized) {
                    instance.onResize();
                }

                if (isScrolled) {
                    instance.onScroll();
                }
            });
            global__WEBPACK_IMPORTED_MODULE_1__['window'].requestAnimationFrame(updateParallax);
        }

        let instanceID = 0; // Jarallax class

        const Jarallax = /*#__PURE__*/ function() {
            function Jarallax(item, userOptions) {
                _classCallCheck(this, Jarallax);

                const self = this;
                self.instanceID = instanceID;
                instanceID += 1;
                self.$item = item;
                self.defaults = {
                    type: 'scroll',
                    // type of parallax: scroll, scale, opacity, scale-opacity, scroll-opacity
                    speed: 0.5,
                    // supported value from -1 to 2
                    imgSrc: null,
                    imgElement: '.jarallax-img',
                    imgSize: 'cover',
                    imgPosition: '50% 50%',
                    imgRepeat: 'no-repeat',
                    // supported only for background, not for <img> tag
                    keepImg: false,
                    // keep <img> tag in it's default place
                    elementInViewport: null,
                    zIndex: -100,
                    disableParallax: false,
                    disableVideo: false,
                    // video
                    videoSrc: null,
                    videoStartTime: 0,
                    videoEndTime: 0,
                    videoVolume: 0,
                    videoLoop: true,
                    videoPlayOnlyVisible: true,
                    videoLazyLoading: true,
                    // events
                    onScroll: null,
                    // function(calculations) {}
                    onInit: null,
                    // function() {}
                    onDestroy: null,
                    // function() {}
                    onCoverImage: null, // function() {}

                }; // prepare data-options

                const dataOptions = self.$item.dataset || {};
                const pureDataOptions = {};
                Object.keys(dataOptions).forEach(function(key) {
                    const loweCaseOption = key.substr(0, 1).toLowerCase() + key.substr(1);

                    if (loweCaseOption && 'undefined' !== typeof self.defaults[loweCaseOption]) {
                        pureDataOptions[loweCaseOption] = dataOptions[key];
                    }
                });
                self.options = self.extend({}, self.defaults, pureDataOptions, userOptions);
                self.pureOptions = self.extend({}, self.options); // prepare 'true' and 'false' strings to boolean

                Object.keys(self.options).forEach(function(key) {
                    if ('true' === self.options[key]) {
                        self.options[key] = true;
                    } else if ('false' === self.options[key]) {
                        self.options[key] = false;
                    }
                }); // fix speed option [-1.0, 2.0]

                self.options.speed = Math.min(2, Math.max(-1, parseFloat(self.options.speed))); // prepare disableParallax callback

                if ('string' === typeof self.options.disableParallax) {
                    self.options.disableParallax = new RegExp(self.options.disableParallax);
                }

                if (self.options.disableParallax instanceof RegExp) {
                    const disableParallaxRegexp = self.options.disableParallax;

                    self.options.disableParallax = function() {
                        return disableParallaxRegexp.test(navigator.userAgent);
                    };
                }

                if ('function' !== typeof self.options.disableParallax) {
                    self.options.disableParallax = function() {
                        return false;
                    };
                } // prepare disableVideo callback


                if ('string' === typeof self.options.disableVideo) {
                    self.options.disableVideo = new RegExp(self.options.disableVideo);
                }

                if (self.options.disableVideo instanceof RegExp) {
                    const disableVideoRegexp = self.options.disableVideo;

                    self.options.disableVideo = function() {
                        return disableVideoRegexp.test(navigator.userAgent);
                    };
                }

                if ('function' !== typeof self.options.disableVideo) {
                    self.options.disableVideo = function() {
                        return false;
                    };
                } // custom element to check if parallax in viewport


                let elementInVP = self.options.elementInViewport; // get first item from array

                if (elementInVP && 'object' === _typeof(elementInVP) && 'undefined' !== typeof elementInVP.length) {
                    const _elementInVP = elementInVP;

                    const _elementInVP2 = _slicedToArray(_elementInVP, 1);

                    elementInVP = _elementInVP2[0];
                } // check if dom element


                if (!(elementInVP instanceof Element)) {
                    elementInVP = null;
                }

                self.options.elementInViewport = elementInVP;
                self.image = {
                    src: self.options.imgSrc || null,
                    $container: null,
                    useImgTag: false,
                    // position fixed is needed for the most of browsers because absolute position have glitches
                    // on MacOS with smooth scroll there is a huge lags with absolute position - https://github.com/nk-o/jarallax/issues/75
                    // on mobile devices better scrolled with absolute position
                    position: /iPad|iPhone|iPod|Android/.test(navigator.userAgent) ? 'absolute' : 'fixed',
                };

                if (self.initImg() && self.canInitParallax()) {
                    self.init();
                }
            } // add styles to element
            // eslint-disable-next-line class-methods-use-this


            _createClass(Jarallax, [{
                key: 'css',
                value: function css(el, styles) {
                    if ('string' === typeof styles) {
                        return global__WEBPACK_IMPORTED_MODULE_1__['window'].getComputedStyle(el).getPropertyValue(styles);
                    } // add transform property with vendor prefix


                    if (styles.transform && supportTransform) {
                        styles[supportTransform] = styles.transform;
                    }

                    Object.keys(styles).forEach(function(key) {
                        el.style[key] = styles[key];
                    });
                    return el;
                }, // Extend like jQuery.extend
                // eslint-disable-next-line class-methods-use-this

            }, {
                key: 'extend',
                value: function extend(out) {
                    for (var _len = arguments.length, args = new Array(_len > 1 ? _len - 1 : 0), _key = 1; _key < _len; _key++) {
                        args[_key - 1] = arguments[_key];
                    }

                    out = out || {};
                    Object.keys(args).forEach(function(i) {
                        if (!args[i]) {
                            return;
                        }

                        Object.keys(args[i]).forEach(function(key) {
                            out[key] = args[i][key];
                        });
                    });
                    return out;
                }, // get window size and scroll position. Useful for extensions
                // eslint-disable-next-line class-methods-use-this

            }, {
                key: 'getWindowData',
                value: function getWindowData() {
                    return {
                        width: global__WEBPACK_IMPORTED_MODULE_1__['window'].innerWidth || document.documentElement.clientWidth,
                        height: wndH,
                        y: document.documentElement.scrollTop,
                    };
                }, // Jarallax functions

            }, {
                key: 'initImg',
                value: function initImg() {
                    const self = this; // find image element

                    let $imgElement = self.options.imgElement;

                    if ($imgElement && 'string' === typeof $imgElement) {
                        $imgElement = self.$item.querySelector($imgElement);
                    } // check if dom element


                    if (!($imgElement instanceof Element)) {
                        if (self.options.imgSrc) {
                            $imgElement = new Image();
                            $imgElement.src = self.options.imgSrc;
                        } else {
                            $imgElement = null;
                        }
                    }

                    if ($imgElement) {
                        if (self.options.keepImg) {
                            self.image.$item = $imgElement.cloneNode(true);
                        } else {
                            self.image.$item = $imgElement;
                            self.image.$itemParent = $imgElement.parentNode;
                        }

                        self.image.useImgTag = true;
                    } // true if there is img tag


                    if (self.image.$item) {
                        return true;
                    } // get image src


                    if (null === self.image.src) {
                        self.image.src = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
                        self.image.bgImage = self.css(self.$item, 'background-image');
                    }

                    return !(!self.image.bgImage || 'none' === self.image.bgImage);
                },
            }, {
                key: 'canInitParallax',
                value: function canInitParallax() {
                    return supportTransform && !this.options.disableParallax();
                },
            }, {
                key: 'init',
                value: function init() {
                    const self = this;
                    const containerStyles = {
                        position: 'absolute',
                        top: 0,
                        left: 0,
                        width: '100%',
                        height: '100%',
                        overflow: 'hidden',
                    };
                    let imageStyles = {
                        pointerEvents: 'none',
                        transformStyle: 'preserve-3d',
                        backfaceVisibility: 'hidden',
                        willChange: 'transform,opacity',
                    };

                    if (!self.options.keepImg) {
                        // save default user styles
                        const curStyle = self.$item.getAttribute('style');

                        if (curStyle) {
                            self.$item.setAttribute('data-jarallax-original-styles', curStyle);
                        }

                        if (self.image.useImgTag) {
                            const curImgStyle = self.image.$item.getAttribute('style');

                            if (curImgStyle) {
                                self.image.$item.setAttribute('data-jarallax-original-styles', curImgStyle);
                            }
                        }
                    } // set relative position and z-index to the parent


                    if ('static' === self.css(self.$item, 'position')) {
                        self.css(self.$item, {
                            position: 'relative',
                        });
                    }

                    if ('auto' === self.css(self.$item, 'z-index')) {
                        self.css(self.$item, {
                            zIndex: 0,
                        });
                    } // container for parallax image


                    self.image.$container = document.createElement('div');
                    self.css(self.image.$container, containerStyles);
                    self.css(self.image.$container, {
                        'z-index': self.options.zIndex,
                    }); // fix for IE https://github.com/nk-o/jarallax/issues/110

                    if (isIE) {
                        self.css(self.image.$container, {
                            opacity: 0.9999,
                        });
                    }

                    self.image.$container.setAttribute('id', 'jarallax-container-'.concat(self.instanceID));
                    self.$item.appendChild(self.image.$container); // use img tag

                    if (self.image.useImgTag) {
                        imageStyles = self.extend({
                            'object-fit': self.options.imgSize,
                            'object-position': self.options.imgPosition,
                            // support for plugin https://github.com/bfred-it/object-fit-images
                            'font-family': 'object-fit: '.concat(self.options.imgSize, '; object-position: ').concat(self.options.imgPosition, ';'),
                            'max-width': 'none',
                        }, containerStyles, imageStyles); // use div with background image
                    } else {
                        self.image.$item = document.createElement('div');

                        if (self.image.src) {
                            imageStyles = self.extend({
                                'background-position': self.options.imgPosition,
                                'background-size': self.options.imgSize,
                                'background-repeat': self.options.imgRepeat,
                                'background-image': self.image.bgImage || 'url("'.concat(self.image.src, '")'),
                            }, containerStyles, imageStyles);
                        }
                    }

                    if ('opacity' === self.options.type || 'scale' === self.options.type || 'scale-opacity' === self.options.type || 1 === self.options.speed) {
                        self.image.position = 'absolute';
                    } // 1. Check if one of parents have transform style (without this check, scroll transform will be inverted if used parallax with position fixed)
                    //    discussion - https://github.com/nk-o/jarallax/issues/9
                    // 2. Check if parents have overflow scroll


                    if ('fixed' === self.image.position) {
                        const $parents = getParents(self.$item).filter(function(el) {
                            const styles = global__WEBPACK_IMPORTED_MODULE_1__['window'].getComputedStyle(el);
                            const parentTransform = styles['-webkit-transform'] || styles['-moz-transform'] || styles.transform;
                            const overflowRegex = /(auto|scroll)/;
                            return parentTransform && 'none' !== parentTransform || overflowRegex.test(styles.overflow + styles['overflow-y'] + styles['overflow-x']);
                        });
                        self.image.position = $parents.length ? 'absolute' : 'fixed';
                    } // add position to parallax block


                    imageStyles.position = self.image.position; // insert parallax image

                    self.css(self.image.$item, imageStyles);
                    self.image.$container.appendChild(self.image.$item); // set initial position and size

                    self.onResize();
                    self.onScroll(true); // call onInit event

                    if (self.options.onInit) {
                        self.options.onInit.call(self);
                    } // remove default user background


                    if ('none' !== self.css(self.$item, 'background-image')) {
                        self.css(self.$item, {
                            'background-image': 'none',
                        });
                    }

                    self.addToParallaxList();
                }, // add to parallax instances list

            }, {
                key: 'addToParallaxList',
                value: function addToParallaxList() {
                    jarallaxList.push({
                        instance: this,
                    });

                    if (1 === jarallaxList.length) {
                        global__WEBPACK_IMPORTED_MODULE_1__['window'].requestAnimationFrame(updateParallax);
                    }
                }, // remove from parallax instances list

            }, {
                key: 'removeFromParallaxList',
                value: function removeFromParallaxList() {
                    const self = this;
                    jarallaxList.forEach(function(data, key) {
                        if (data.instance.instanceID === self.instanceID) {
                            jarallaxList.splice(key, 1);
                        }
                    });
                },
            }, {
                key: 'destroy',
                value: function destroy() {
                    const self = this;
                    self.removeFromParallaxList(); // return styles on container as before jarallax init

                    const originalStylesTag = self.$item.getAttribute('data-jarallax-original-styles');
                    self.$item.removeAttribute('data-jarallax-original-styles'); // null occurs if there is no style tag before jarallax init

                    if (!originalStylesTag) {
                        self.$item.removeAttribute('style');
                    } else {
                        self.$item.setAttribute('style', originalStylesTag);
                    }

                    if (self.image.useImgTag) {
                        // return styles on img tag as before jarallax init
                        const originalStylesImgTag = self.image.$item.getAttribute('data-jarallax-original-styles');
                        self.image.$item.removeAttribute('data-jarallax-original-styles'); // null occurs if there is no style tag before jarallax init

                        if (!originalStylesImgTag) {
                            self.image.$item.removeAttribute('style');
                        } else {
                            self.image.$item.setAttribute('style', originalStylesTag);
                        } // move img tag to its default position


                        if (self.image.$itemParent) {
                            self.image.$itemParent.appendChild(self.image.$item);
                        }
                    } // remove additional dom elements


                    if (self.$clipStyles) {
                        self.$clipStyles.parentNode.removeChild(self.$clipStyles);
                    }

                    if (self.image.$container) {
                        self.image.$container.parentNode.removeChild(self.image.$container);
                    } // call onDestroy event


                    if (self.options.onDestroy) {
                        self.options.onDestroy.call(self);
                    } // delete jarallax from item


                    delete self.$item.jarallax;
                }, // it will remove some image overlapping
                // overlapping occur due to an image position fixed inside absolute position element

            }, {
                key: 'clipContainer',
                value: function clipContainer() {
                    // needed only when background in fixed position
                    if ('fixed' !== this.image.position) {
                        return;
                    }

                    const self = this;
                    const rect = self.image.$container.getBoundingClientRect();
                    const width = rect.width,
                        height = rect.height;

                    if (!self.$clipStyles) {
                        self.$clipStyles = document.createElement('style');
                        self.$clipStyles.setAttribute('type', 'text/css');
                        self.$clipStyles.setAttribute('id', 'jarallax-clip-'.concat(self.instanceID));
                        const head = document.head || document.getElementsByTagName('head')[0];
                        head.appendChild(self.$clipStyles);
                    } // clip is used for old browsers.
                    // clip-path for modern browsers (also fixes Safari v14 bug https://github.com/nk-o/jarallax/issues/181 ).


                    const styles = '#jarallax-container-'.concat(self.instanceID, ' {\n            clip: rect(0 ').concat(width, 'px ').concat(height, 'px 0);\n            clip: rect(0, ').concat(width, 'px, ').concat(height, 'px, 0);\n            -webkit-clip-path: polygon(0 0, 100% 0, 100% 100%, 0 100%);\n            clip-path: polygon(0 0, 100% 0, 100% 100%, 0 100%);\n        }'); // add clip styles inline (this method need for support IE8 and less browsers)

                    if (self.$clipStyles.styleSheet) {
                        self.$clipStyles.styleSheet.cssText = styles;
                    } else {
                        self.$clipStyles.innerHTML = styles;
                    }
                },
            }, {
                key: 'coverImage',
                value: function coverImage() {
                    const self = this;
                    const rect = self.image.$container.getBoundingClientRect();
                    const contH = rect.height;
                    const speed = self.options.speed;
                    const isScroll = 'scroll' === self.options.type || 'scroll-opacity' === self.options.type;
                    let scrollDist = 0;
                    let resultH = contH;
                    let resultMT = 0; // scroll parallax

                    if (isScroll) {
                        // scroll distance and height for image
                        if (0 > speed) {
                            scrollDist = speed * Math.max(contH, wndH);

                            if (wndH < contH) {
                                scrollDist -= speed * (contH - wndH);
                            }
                        } else {
                            scrollDist = speed * (contH + wndH);
                        } // size for scroll parallax


                        if (1 < speed) {
                            resultH = Math.abs(scrollDist - wndH);
                        } else if (0 > speed) {
                            resultH = scrollDist / speed + Math.abs(scrollDist);
                        } else {
                            resultH += (wndH - contH) * (1 - speed);
                        }

                        scrollDist /= 2;
                    } // store scroll distance


                    self.parallaxScrollDistance = scrollDist; // vertical center

                    if (isScroll) {
                        resultMT = (wndH - resultH) / 2;
                    } else {
                        resultMT = (contH - resultH) / 2;
                    } // apply result to item


                    self.css(self.image.$item, {
                        height: ''.concat(resultH, 'px'),
                        marginTop: ''.concat(resultMT, 'px'),
                        left: 'fixed' === self.image.position ? ''.concat(rect.left, 'px') : '0',
                        width: ''.concat(rect.width, 'px'),
                    }); // call onCoverImage event

                    if (self.options.onCoverImage) {
                        self.options.onCoverImage.call(self);
                    } // return some useful data. Used in the video cover function


                    return {
                        image: {
                            height: resultH,
                            marginTop: resultMT,
                        },
                        container: rect,
                    };
                },
            }, {
                key: 'isVisible',
                value: function isVisible() {
                    return this.isElementInViewport || false;
                },
            }, {
                key: 'onScroll',
                value: function onScroll(force) {
                    const self = this;
                    const rect = self.$item.getBoundingClientRect();
                    const contT = rect.top;
                    const contH = rect.height;
                    const styles = {}; // check if in viewport

                    let viewportRect = rect;

                    if (self.options.elementInViewport) {
                        viewportRect = self.options.elementInViewport.getBoundingClientRect();
                    }

                    self.isElementInViewport = 0 <= viewportRect.bottom && 0 <= viewportRect.right && viewportRect.top <= wndH && viewportRect.left <= global__WEBPACK_IMPORTED_MODULE_1__['window'].innerWidth; // stop calculations if item is not in viewport

                    if (force ? false : !self.isElementInViewport) {
                        return;
                    } // calculate parallax helping variables


                    const beforeTop = Math.max(0, contT);
                    const beforeTopEnd = Math.max(0, contH + contT);
                    const afterTop = Math.max(0, -contT);
                    const beforeBottom = Math.max(0, contT + contH - wndH);
                    const beforeBottomEnd = Math.max(0, contH - (contT + contH - wndH));
                    const afterBottom = Math.max(0, -contT + wndH - contH);
                    const fromViewportCenter = 1 - 2 * ((wndH - contT) / (wndH + contH)); // calculate on how percent of section is visible

                    let visiblePercent = 1;

                    if (contH < wndH) {
                        visiblePercent = 1 - (afterTop || beforeBottom) / contH;
                    } else if (beforeTopEnd <= wndH) {
                        visiblePercent = beforeTopEnd / wndH;
                    } else if (beforeBottomEnd <= wndH) {
                        visiblePercent = beforeBottomEnd / wndH;
                    } // opacity


                    if ('opacity' === self.options.type || 'scale-opacity' === self.options.type || 'scroll-opacity' === self.options.type) {
                        styles.transform = 'translate3d(0,0,0)';
                        styles.opacity = visiblePercent;
                    } // scale


                    if ('scale' === self.options.type || 'scale-opacity' === self.options.type) {
                        let scale = 1;

                        if (0 > self.options.speed) {
                            scale -= self.options.speed * visiblePercent;
                        } else {
                            scale += self.options.speed * (1 - visiblePercent);
                        }

                        styles.transform = 'scale('.concat(scale, ') translate3d(0,0,0)');
                    } // scroll


                    if ('scroll' === self.options.type || 'scroll-opacity' === self.options.type) {
                        let positionY = self.parallaxScrollDistance * fromViewportCenter; // fix if parallax block in absolute position

                        if ('absolute' === self.image.position) {
                            positionY -= contT;
                        }

                        styles.transform = 'translate3d(0,'.concat(positionY, 'px,0)');
                    }

                    self.css(self.image.$item, styles); // call onScroll event

                    if (self.options.onScroll) {
                        self.options.onScroll.call(self, {
                            section: rect,
                            beforeTop: beforeTop,
                            beforeTopEnd: beforeTopEnd,
                            afterTop: afterTop,
                            beforeBottom: beforeBottom,
                            beforeBottomEnd: beforeBottomEnd,
                            afterBottom: afterBottom,
                            visiblePercent: visiblePercent,
                            fromViewportCenter: fromViewportCenter,
                        });
                    }
                },
            }, {
                key: 'onResize',
                value: function onResize() {
                    this.coverImage();
                    this.clipContainer();
                },
            }]);

            return Jarallax;
        }(); // global definition


        const plugin = function plugin(items, options) {
            // check for dom element
            // thanks: http://stackoverflow.com/questions/384286/javascript-isdom-how-do-you-check-if-a-javascript-object-is-a-dom-object
            if ('object' === (typeof HTMLElement === 'undefined' ? 'undefined' : _typeof(HTMLElement)) ? items instanceof HTMLElement : items && 'object' === _typeof(items) && null !== items && 1 === items.nodeType && 'string' === typeof items.nodeName) {
                items = [items];
            }

            const len = items.length;
            let k = 0;
            let ret;

            for (var _len2 = arguments.length, args = new Array(_len2 > 2 ? _len2 - 2 : 0), _key2 = 2; _key2 < _len2; _key2++) {
                args[_key2 - 2] = arguments[_key2];
            }

            for (k; k < len; k += 1) {
                if ('object' === _typeof(options) || 'undefined' === typeof options) {
                    if (!items[k].jarallax) {
                        items[k].jarallax = new Jarallax(items[k], options);
                    }
                } else if (items[k].jarallax) {
                    // eslint-disable-next-line prefer-spread
                    ret = items[k].jarallax[options].apply(items[k].jarallax, args);
                }

                if ('undefined' !== typeof ret) {
                    return ret;
                }
            }

            return items;
        };

        plugin.constructor = Jarallax;
        /* harmony default export */
        __webpack_exports__['default'] = (plugin);

        /***/
    }),
    /******/
]);