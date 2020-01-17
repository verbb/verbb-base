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
        var $tabsContainer = $('[data-vui-tabs]');

        if ($tabsContainer) {
            new Verbb.UI.SimpleTabs($tabsContainer);
        }
    },
});

Verbb.UI.SimpleTabs = Garnish.Base.extend({
    init: function($tabsContainer) {
        // Clear out all our old info in case the tabs were just replaced
        this.$tabsList = this.$tabs = this.$selectedTab = this.selectedTabIndex = null;

        this.$tabsContainer = $tabsContainer;

        if (!this.$tabsContainer.length) {
            this.$tabsContainer = null;
            return;
        }

        this.$tabsList = this.$tabsContainer.find('> ul');
        this.$tabs = this.$tabsList.find('> li');

        var i, $tab, $a, href;

        for (i = 0; i < this.$tabs.length; i++) {
            $tab = this.$tabs.eq(i);

            // Does it link to an anchor?
            $a = $tab.children('a');
            href = $a.attr('href');

            if (href && href.charAt(0) === '#') {
                this.addListener($a, 'click', function(ev) {
                    ev.preventDefault();
                    this.selectTab(ev.currentTarget);
                });

                if (encodeURIComponent(href.substr(1)) === document.location.hash.substr(1)) {
                    this.selectTab($a);
                }
            }

            if (!this.$selectedTab && $a.hasClass('sel')) {
                this._selectTab($a, i);
            }
        }
    },

    selectTab: function(tab) {
        var $tab = $(tab);

        if (this.$selectedTab) {
            if (this.$selectedTab.get(0) === $tab.get(0)) {
                return;
            }

            this.deselectTab();
        }

        $tab.addClass('sel');

        var href = $tab.attr('href')
        $(href).removeClass('hidden');

        if (typeof history !== 'undefined') {
            history.replaceState(undefined, undefined, href);
        }

        this._selectTab($tab, this.$tabs.index($tab.parent()));
        this.updateTabs();

        // Fixes Redactor fixed toolbars on previously hidden panes
        Garnish.$doc.trigger('scroll');
    },

    _selectTab: function($tab, index) {
        this.$selectedTab = $tab;
        this.selectedTabIndex = index;
    },

    deselectTab: function() {
        if (!this.$selectedTab) {
            return;
        }

        this.$selectedTab.removeClass('sel');

        if (this.$selectedTab.attr('href').charAt(0) === '#') {
            $(this.$selectedTab.attr('href')).addClass('hidden');
        }

        this._selectTab(null, null);
    },

    handleWindowResize: function() {
        this.updateTabs();
    },

    updateTabs: function() {
        if (!this.$tabsContainer) {
            return;
        }

        var maxWidth = Math.floor(this.$tabsContainer.width()) - 40;
        var totalWidth = 0;
        var tabMargin = Garnish.$bod.width() >= 768 ? -12 : -7;
        var $tab;

        // Start with the selected tab, because that needs to be visible
        if (this.$selectedTab) {
            this.$selectedTab.parent('li').appendTo(this.$tabsList);
            totalWidth = Math.ceil(this.$selectedTab.parent('li').width());
        }

        for (var i = 0; i < this.$tabs.length; i++) {
            $tab = this.$tabs.eq(i).appendTo(this.$tabsList);
            if (i !== this.selectedTabIndex) {
                totalWidth += Math.ceil($tab.width());
                // account for the negative margin
                if (i !== 0 || this.$selectedTab) {
                    totalWidth += tabMargin;
                }
            }

            if (i === this.selectedTabIndex || totalWidth <= maxWidth) {
                $tab.find('> a').removeAttr('role');
            }
        }
    },
});

// Initialise immediately
new Verbb.UI();

})(jQuery);