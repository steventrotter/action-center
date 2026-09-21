(function() {
  const { registerBlockType } = wp.blocks;
  const { InspectorControls, useBlockProps } = wp.blockEditor;
  const { PanelBody, RangeControl, SelectControl, ToggleControl } = wp.components;
  const { __ } = wp.i18n;
  const { serverSideRender: ServerSideRender } = wp;

  registerBlockType('cta-manager/upcoming-ctas', {
    edit: function(props) {
      const { attributes, setAttributes } = props;
      const blockProps = useBlockProps();

      // Get taxonomies from window object (passed from PHP)
      const types = window.ctaBlockData?.types || [];
      const orgs = window.ctaBlockData?.orgs || [];

      return (
        wp.element.createElement('div', blockProps,
          wp.element.createElement(InspectorControls, null,
            wp.element.createElement(PanelBody, { title: __('Display Settings', 'action-center') },
              wp.element.createElement(RangeControl, {
                label: __('Number of CTAs', 'action-center'),
                value: attributes.limit,
                onChange: (value) => setAttributes({ limit: value }),
                min: 1,
                max: 10
              }),
              wp.element.createElement(SelectControl, {
                label: __('Action Mode', 'action-center'),
                value: attributes.actionMode,
                options: [
                  { label: __('Urgent Actions only (default)', 'action-center'), value: 'urgent' },
                  { label: __('Ongoing Actions only', 'action-center'), value: 'ongoing' },
                  { label: __('All Actions', 'action-center'), value: 'all' },
                  { label: __('Show ongoing only as fallback', 'action-center'), value: 'fallback' },
                ],
                onChange: (value) => setAttributes({ actionMode: value }),
                help: __('Controls which types of actions appear in this block.', 'action-center')
              }),
              wp.element.createElement(ToggleControl, {
                label: __('Show Deadlines', 'action-center'),
                checked: attributes.showDeadline,
                onChange: (value) => setAttributes({ showDeadline: value })
              }),
              wp.element.createElement(ToggleControl, {
                label: __('Show View More Button', 'action-center'),
                help: __('When enabled, a View More button appears if there are more CTAs available than the display limit.', 'action-center'),
                checked: attributes.showViewMore,
                onChange: (value) => setAttributes({ showViewMore: value })
              })
            ),
            wp.element.createElement(PanelBody, { title: __('Filter Settings', 'action-center'), initialOpen: false },
              types.length > 0 && wp.element.createElement(SelectControl, {
                label: __('Filter by Type', 'action-center'),
                value: attributes.ctaType,
                options: [
                  { label: __('All Types', 'action-center'), value: '' },
                  ...types.map(t => ({ label: t.name, value: t.slug }))
                ],
                onChange: (value) => setAttributes({ ctaType: value })
              }),
              orgs.length > 0 && wp.element.createElement(SelectControl, {
                label: __('Filter by Organization', 'action-center'),
                value: attributes.ctaOrg,
                options: [
                  { label: __('All Organizations', 'action-center'), value: '' },
                  ...orgs.map(o => ({ label: o.name, value: o.slug }))
                ],
                onChange: (value) => setAttributes({ ctaOrg: value })
              })
            )
          ),
          wp.element.createElement(ServerSideRender, {
            block: 'cta-manager/upcoming-ctas',
            attributes: attributes
          })
        )
      );
    },

    save: function() {
      // Server-side rendering
      return null;
    }
  });
})();