define(function(require) {
    'use strict';

    var $ = require('jquery');
    var _ = require('underscore');
    var Popper = require('popper');
    require('bootstrap-dropdown');

    var Dropdown = $.fn.dropdown.Constructor;
    var original = _.clone(Dropdown.prototype);
    var _clearMenus = Dropdown._clearMenus;

    var DATA_KEY = 'bs.dropdown';
    var EVENT_KEY = '.' + DATA_KEY;
    var DATA_API_KEY = '.data-api';
    var HIDE_EVENT = 'hide' + EVENT_KEY;
    var TO_HIDE_EVENT = 'tohide' + EVENT_KEY;
    var ATTACHMENT_CLASSES = 'dropdown-menu--top dropdown-menu--bottom dropdown-menu--left dropdown-menu--right';
    var DROPDOWN_WITH_ARROW_SELECTOR = '.dropdown--with-arrow';
    var GRID_SCROLLABLE_CONTAINER = '.grid-scrollable-container';
    var DIALOG_SCROLLABLE_CONTAINER = '.ui-dialog-content';
    var SCROLLABLE_CONTAINER = [
        DIALOG_SCROLLABLE_CONTAINER,
        GRID_SCROLLABLE_CONTAINER
    ].join(',');

    _.extend(Dropdown.prototype, {
        toggle: function() {
            Dropdown._togglingElement = this._element;
            Dropdown._isShowing = !$(this._menu).hasClass('show');

            original.toggle.call(this);

            if (Dropdown._isShowing) {
                // sets focus on first input
                $(this._menu).find('input[type=text]:first').focus();
            }

            delete Dropdown._togglingElement;
            delete Dropdown._isShowing;
        },

        dispose: function() {
            var parent = Dropdown._getParentFromElement(this._element);
            $(parent).off(EVENT_KEY);
            $(document).off('mCSB.scroll' + EVENT_KEY, this._onCustomScroll);
            original.dispose.call(this);
        },

        _getConfig: function() {
            var config = original._getConfig.call(this);
            var placement = config.placement;

            if (
                placement && _.isRTL() &&
                (placement = placement.split('-')).length === 2 &&
                ['auto', 'top', 'bottom'].indexOf(placement[0]) !== -1 &&
                ['start', 'end'].indexOf(placement[1]) !== -1
            ) {
                placement[1] = {start: 'end', end: 'start'}[placement[1]];
                config.placement = placement.join('-');
            }

            return config;
        },

        _getMenuElement: function() {
            original._getMenuElement.call(this);

            if (!this._menu) {
                // if the menu element wasn't found by selector `.dropdown-menu`,
                // the element next to toggler button is considered as menu
                this._menu = $(this._element).next();
            }

            return this._menu;
        },

        _addEventListeners: function() {
            this._onCustomScroll = this._onCustomScroll.bind(this);

            original._addEventListeners.call(this);

            var parent = Dropdown._getParentFromElement(this._element);

            $(this._element).add(parent).on(TO_HIDE_EVENT, function(event) {
                event.stopImmediatePropagation();
                if ($(this._menu).hasClass('show')) {
                    this.toggle();
                }
            }.bind(this));

            $(parent).on(HIDE_EVENT, this._onHide.bind(this));
            $(document).on('mCSB.scroll' + EVENT_KEY, this._onCustomScroll);
        },

        _onCustomScroll: function() {
            if (this._popper) {
                // When scrolling leads to hidden dropdown appears again, single call of scroll handler
                // shows dropdown menu in wrong position. But since single scroll event happens very
                // rarely in real life the next scroll event sets dropdown menu correctly.
                // To emulate similar effect for custom scroll just call `scheduleUpdate` twice
                this._popper.scheduleUpdate();
                this._popper.scheduleUpdate();
            }
        },

        /**
         * Handles 'hide' event triggered from _clearMenus
         *
         * @param event
         * @protected
         */
        _onHide: function(event) {
            var form;

            if (this._element !== event.relatedTarget) {
                return;
            }

            if (Dropdown._isShowing && $.contains(this._menu, Dropdown._togglingElement)) {
                // prevent parent menu close on opening nested dropdown
                event.preventDefault();
            }

            if (
                Dropdown._clickEvent &&
                (form = $(Dropdown._clickEvent.target).closest('form')[0]) &&
                $.contains(this._menu, form)
            ) {
                // prevent parent menu close on click inside its form
                event.preventDefault();
            }
        },

        _getPopperConfig: function() {
            var config = original._getPopperConfig.call(this);

            if (!config.positionFixed && $(this._element).closest(SCROLLABLE_CONTAINER).length) {
                // dropdowns are shown with position fixed inside scrollable container, to fix overflow
                config.positionFixed = true;
            }

            if (this._config.inheritParentWidth) {
                var inheritParentWidth = this._config.inheritParentWidth;
                config.positionFixed = true;
                config.modifiers.computeStyle = {
                    fn: function(data, options) {
                        Popper.Defaults.modifiers.computeStyle.fn(data, options);

                        var popper = data.instance.popper;
                        var offset = data.offsets.popper;

                        if (inheritParentWidth === 'strictly' || offset.width < popper.parentElement.clientWidth) {
                            data.styles.width = popper.parentElement.clientWidth;
                            data.styles.left = data.styles.left - (popper.parentElement.clientWidth - offset.width);
                        }

                        return data;
                    }
                };
            }

            // https://popper.js.org/popper-documentation.html#Popper.Defaults
            _.extend(config, _.pick(this._config, 'placement', 'positionFixed', 'eventsEnabled'));
            _.extend(config.modifiers, _.pick(this._config.modifiers, 'shift', 'offset', 'preventOverflow',
                'keepTogether', 'arrow', 'flip', 'inner', 'hide', 'computeStyle', 'applyStyle'));

            if (this._popper !== null) {
                // the fix deletes previews instance to prevent memory leaks
                // _getPopperConfig is invoked only before creating a new Popper instance
                this._popper.destroy();
                this._popper = null;
            }

            var menu = this._getMenuElement();

            if ($(menu).closest(DROPDOWN_WITH_ARROW_SELECTOR).length) {
                var arrow = $(menu).children('.arrow')[0];

                if (!arrow) {
                    arrow = document.createElement(menu.tagName.toLowerCase() === 'ul' ? 'li' : 'span');
                    arrow.classList.add('arrow');
                    menu.insertBefore(arrow, menu.firstChild);
                }

                config.modifiers.arrow = _.extend(config.modifiers.arrow || {}, {
                    element: arrow
                });

                _.extend(config, {
                    onCreate: this._handlePopperPlacementChange.bind(this),
                    onUpdate: this._handlePopperPlacementChange.bind(this)
                });
            }

            if (_.result(config.modifiers, 'preventOverflow')) {
                var boundariesElement = config.modifiers.preventOverflow.boundariesElement;

                if (['scrollParent', 'window', 'viewport'].indexOf(boundariesElement) === -1) {
                    config.modifiers.preventOverflow.boundariesElement = $(this._element).closest(boundariesElement)[0];
                }
            }

            return config;
        },

        _handlePopperPlacementChange: function(data) {
            var menu = this._getMenuElement();
            var side = data.placement.split('-')[0];

            $(menu).removeClass(ATTACHMENT_CLASSES).addClass('dropdown-menu--' + side);
        },

        /**
         * Defined property `_inNavbar` is used only for
         *
         * @return {boolean}
         * @protected
         */
        _detectNavbar: function() {
            return original._detectNavbar.call(this) ||
                this._config.popper === false || // popper plugin is turned off intentionally
                $(this._element).closest('.app-header').length > 0; // app-header is considered as navbar as well
        }
    });

    Dropdown._clearMenus = function(event) {
        if (event && event.type === 'click') {
            var $target = $(event.target);
            if ($target.closest('[data-toggle]').length && $target.closest('.dropdown-menu.show').length) {
                // click on toggle element inside active dropdown-menu
                return;
            }

            if ($target.closest('.dropdown-menu.show').length) {
                // original click event is used in the hide event handler
                Dropdown._clickEvent = event;
            }
        }

        _clearMenus(event);

        delete Dropdown._clickEvent;
    };

    function _events(names) {
        return names.map(function(name) {
            return name + EVENT_KEY + DATA_API_KEY;
        }).join(' ');
    }

    $(document)
        // replaced _clearMenus handler with custom one
        .off(_events(['click', 'keyup']), _clearMenus)
        .on(_events(['click', 'keyup', 'clearMenus']), Dropdown._clearMenus)

        // nested form click events are processed in _clearMenus method extend
        .off(_events(['click']), '.dropdown form')
        .on(_events(['disposeLayout']), function(event) {
            $('[data-toggle="dropdown"]', event.target).each(function() {
                var $toogler = $(this);
                if ($toogler.data('bs.dropdown')) {
                    $toogler.dropdown('dispose');
                }
            });
        });
});
