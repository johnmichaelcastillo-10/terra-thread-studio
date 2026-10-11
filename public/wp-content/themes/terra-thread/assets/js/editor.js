/**
 * Editor registration for the theme's dynamic blocks. Metadata comes from each
 * block.json (registered in PHP); the editor previews them via ServerSideRender,
 * so no build step is required.
 */
(({ blocks, element, serverSideRender: ServerSideRender, blockEditor }) => {
	const { createElement: h } = element;

	for (const name of window.ttBlocks || []) {
		blocks.registerBlockType(name, {
			edit: (props) =>
				h(
					'div',
					blockEditor.useBlockProps(),
					h(ServerSideRender, { block: name, attributes: props.attributes, skipBlockSupportAttributes: true })
				),
			save: () => null,
		});
	}
})(window.wp);
