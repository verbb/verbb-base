// ==========================================================================

// Verbb UI for Craft CMS
// Author: Verbb - https://verbb.io/

// ==========================================================================

if (typeof Verbb === typeof undefined) {
    Verbb = {};
}

(function($) {

Verbb.UI = Garnish.Base.extend({
    init: function() {
        this.$tabsContainer = $('[data-vui-tabs]');

        if (this.$tabsContainer.length) {
            new Verbb.UI.SimpleTabs(this.$tabsContainer);
        }
    },
});

Verbb.UI.SimpleTabs = Garnish.Base.extend({
    $container: null,
    $ul: null,
    $tabs: null,
    $selectedTab: null,
    $focusableTab: null,

    init: function(container) {
        this.$container = $(container);
        this.$ul = this.$container.find('> ul:first');
        this.$tabs = this.$ul.find('> li > a');
        this.$selectedTab = this.$tabs.filter('.sel:first');
        this.$focusableTab = this.$tabs.filter('[tabindex=0]:first');

        // Is there already a tab manager?
        if (this.$container.data('tabs')) {
            Garnish.log('Double-instantiating a tab manager on an element');
            this.$container.data('tabs').destroy();
        }

        this.$container.data('tabs', this);

        for (let i = 0; i < this.$tabs.length; i++) {
            const $a = this.$tabs.eq(i);

            // Does it link to an anchor?
            const href = $a.attr('href');
            if (href && href.charAt(0) === '#') {
                this.addListener($a, 'keydown', ev => {
                    if ([Garnish.SPACE_KEY, Garnish.RETURN_KEY].includes(ev.keyCode)) {
                        ev.preventDefault();
                        this.selectTab(ev.currentTarget);
                    }
                });
                this.addListener($a, 'click', ev => {
                    ev.preventDefault();
                    const $a = $(ev.currentTarget);
                    this.selectTab(ev.currentTarget);
                    this.makeTabFocusable(ev.currentTarget);
                });

                if (href.substr(1) === window.LOCATION_HASH) {
                    $initialTab = $a;
                }
            }

            this.addListener($a, 'keydown', ev => {
                if ([Garnish.DOWN_KEY, Garnish.UP_KEY].includes(ev.keyCode) && $.contains(this.$ul[0], ev.currentTarget)) {
                    let $tab;

                    if (ev.keyCode === Garnish.UP_KEY) {
                        var $prevTab = $(ev.currentTarget).parent().prev('li');

                        if ($prevTab.hasClass('heading')) {
                            $prevTab = $prevTab.prev('li');
                        }

                        $tab = $prevTab.children('a');
                    } else {
                        var $nextTab = $(ev.currentTarget).parent().next('li');

                        if ($nextTab.hasClass('heading')) {
                            $nextTab = $nextTab.next('li');
                        }

                        $tab = $nextTab.children('a');
                    }

                    if ($tab.length) {
                        ev.preventDefault();
                        this.makeTabFocusable($tab);
                        $tab.focus();
                        this.scrollToTab($tab);
                    }
                }
            });
        }

        if (window.LOCATION_HASH) {
            const $tab = this.$tabs.filter(`[href="#${window.LOCATION_HASH}"]`);
            
            if ($tab.length) {
                this.selectTab($tab);
            }
        }
    },

    selectTab: function(tab) {
        const $tab = this._getTab(tab);

        if ($tab[0] === this.$selectedTab[0]) {
            return;
        }

        this.deselectTab();
        this.$selectedTab = $tab.addClass('sel');
        this.makeTabFocusable($tab);
        this.scrollToTab($tab);

        this.trigger('selectTab', {
            $tab: $tab,
        });

        const href = $tab.attr('href');

        // Show its content area
        if (href.charAt(0) === '#') {
            $(href).removeClass('hidden');
        }

        // Trigger a resize event to update any UI components that are listening for it
        Garnish.$win.trigger('resize');

        // Fixes Redactor fixed toolbars on previously hidden panes
        Garnish.$doc.trigger('scroll');

        if (typeof history !== 'undefined') {
            // Delay changing the hash so it doesn't cause the browser to jump on page load
            Garnish.requestAnimationFrame(() => {
                history.replaceState(undefined, undefined, href);
            });
        }
    },

    deselectTab: function() {
        const $tab = this.$selectedTab.removeClass('sel');
        this.$selectedTab = null;

        this.trigger('deselectTab', {
            $tab: $tab,
        });

        if ($tab.attr('href').charAt(0) === '#') {
            // Hide its content area
            $($tab.attr('href')).addClass('hidden');
        }
    },

    makeTabFocusable: function(tab) {
        const $tab = this._getTab(tab);

        if ($tab[0] === this.$focusableTab[0]) {
            return;
        }

        this.$focusableTab.attr('tabindex', '-1');
        this.$focusableTab = $tab.attr('tabindex', '0');
    },

    scrollToTab: function(tab) {
        const $tab = this._getTab(tab);
        const scrollLeft = this.$ul.scrollLeft();
        const tabOffset = $tab.offset().left;
        const elemScrollOffset = tabOffset - this.$ul.offset().left;
        let targetScrollLeft = false;

        // Is the tab hidden on the left?
        if (elemScrollOffset < 0) {
            targetScrollLeft = scrollLeft + elemScrollOffset - 24;
        } else {
            const tabWidth = $tab.outerWidth();
            const ulWidth = this.$ul.prop('clientWidth');

            // Is it hidden to the right?
            if (elemScrollOffset + tabWidth > ulWidth) {
                targetScrollLeft = scrollLeft + (elemScrollOffset - (ulWidth - tabWidth)) + 24;
            }
        }

        if (targetScrollLeft !== false) {
            this.$ul.scrollLeft(targetScrollLeft);
        }
    },

    _getTab: function(tab) {
        if (tab instanceof jQuery) {
            return tab;
        }

        if (tab instanceof HTMLElement) {
            return $(tab);
        }

        if (typeof tab !== 'string') {
            throw 'Invalid tab ID';
        }

        const $tab = this.$tabs.filter(`[data-id="${tab}"]`);

        if (!$tab.length) {
            throw `Invalid tab ID: ${tab}`;
        }

        return $tab;
    },
});

// Initialise immediately
new Verbb.UI();

})(jQuery);