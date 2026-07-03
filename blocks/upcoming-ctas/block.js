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
            wp.element.createElement(PanelBody, { title: __('Display Settings', 'calls-to-action-manager') },
              wp.element.createElement(RangeControl, {
                label: __('Number of CTAs', 'calls-to-action-manager'),
                value: attributes.limit,
                onChange: (value) => setAttributes({ limit: value }),
                min: 1,
                max: 10
              }),
              wp.element.createElement(SelectControl, {
                label: __('Action Mode', 'calls-to-action-manager'),
                value: attributes.actionMode,
                options: [
                  { label: __('Urgent Actions only (default)', 'calls-to-action-manager'), value: 'urgent' },
                  { label: __('Ongoing Actions only', 'calls-to-action-manager'), value: 'ongoing' },
                  { label: __('All Actions', 'calls-to-action-manager'), value: 'all' },
                  { label: __('Show ongoing only as fallback', 'calls-to-action-manager'), value: 'fallback' },
                ],
                onChange: (value) => setAttributes({ actionMode: value }),
                help: __('Controls which types of actions appear in this block.', 'calls-to-action-manager')
              }),
              wp.element.createElement(ToggleControl, {
                label: __('Show Deadlines', 'calls-to-action-manager'),
                checked: attributes.showDeadline,
                onChange: (value) => setAttributes({ showDeadline: value })
              }),
              wp.element.createElement(ToggleControl, {
                label: __('Show View More Button', 'calls-to-action-manager'),
                help: __('When enabled, a View More button appears if there are more CTAs available than the display limit.', 'calls-to-action-manager'),
                checked: attributes.showViewMore,
                onChange: (value) => setAttributes({ showViewMore: value })
              })
            ),
            wp.element.createElement(PanelBody, { title: __('Filter Settings', 'calls-to-action-manager'), initialOpen: false },
              types.length > 0 && wp.element.createElement(SelectControl, {
                label: __('Filter by Type', 'calls-to-action-manager'),
                value: attributes.ctaType,
                options: [
                  { label: __('All Types', 'calls-to-action-manager'), value: '' },
                  ...types.map(t => ({ label: t.name, value: t.slug }))
                ],
                onChange: (value) => setAttributes({ ctaType: value })
              }),
              orgs.length > 0 && wp.element.createElement(SelectControl, {
                label: __('Filter by Organization', 'calls-to-action-manager'),
                value: attributes.ctaOrg,
                options: [
                  { label: __('All Organizations', 'calls-to-action-manager'), value: '' },
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