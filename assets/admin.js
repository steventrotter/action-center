(function ($) {
	'use strict';

	// Counter for unique editor IDs
	var editorCounter = 0;

	// -------- Steps: rich text repeaters --------

	function initStepsRepeater() {
		var wrapper = $('#cta-steps-wrapper');
		if (!wrapper.length) {
			return;
		}

		$('#cta-add-step').on('click', function (e) {
			e.preventDefault();

			var count = wrapper.find('.cta-step-item').length + 1;
			editorCounter++;
			var editorId = 'cta_step_editor_' + editorCounter + '_' + Date.now();

			var $item = $('<div class="cta-step-item"></div>');

			var html = '<p><strong>Step ' + count + '</strong></p>' +
				'<div class="cta-step-editor-wrapper">' +
				'<textarea id="' + editorId + '" name="cta_steps[]" rows="8" class="cta-step-textarea"></textarea>' +
				'</div>' +
				'<p><button type="button" class="button cta-remove-step">Remove Step</button></p>';

			$item.html(html);
			wrapper.append($item);

			setTimeout(function() {
				initializeTinyMCE(editorId, 250);
			}, 100);
		});

		wrapper.on('click', '.cta-remove-step', function (e) {
			e.preventDefault();
			var $stepItem = $(this).closest('.cta-step-item');
			var $textarea = $stepItem.find('textarea[name="cta_steps[]"]');
			var editorId = $textarea.attr('id');

			if (editorId && typeof tinymce !== 'undefined') {
				var editor = tinymce.get(editorId);
				if (editor) {
					editor.remove();
				}
			}

			$stepItem.remove();

			wrapper.find('.cta-step-item').each(function (i) {
				$(this).find('p strong').first().text('Step ' + (i + 1));
			});
		});
	}

	function initializeTinyMCE(editorId, height) {
		if (typeof tinymce === 'undefined' || typeof wp === 'undefined' || typeof wp.editor === 'undefined') {
			console.error('TinyMCE or wp.editor not available');
			return;
		}

		var existingEditor = tinymce.get(editorId);
		if (existingEditor) {
			existingEditor.remove();
		}

		height = height || 250;

		wp.editor.initialize(editorId, {
			tinymce: {
				wpautop: true,
				plugins: 'lists,link,paste,textcolor,wordpress,wplink',
				toolbar1: 'bold,italic,underline,bullist,numlist,link,unlink',
				toolbar2: '',
				height: height,
				menubar: false,
				branding: false,
				elementpath: false,
				resize: true,
				convert_urls: false
			},
			quicktags: false,
			mediaButtons: false
		});
	}

	// -------- Shared: make a wrapper sortable --------

	function initSortable(wrapperId) {
		var $wrapper = $(wrapperId);
		if (!$wrapper.length || !$.fn.sortable) {
			return;
		}
		$wrapper.sortable({
			handle: '.cta-drag-handle',
			axis: 'y',
			placeholder: 'cta-sortable-placeholder',
			forcePlaceholderSize: true
		});
	}

	// -------- Related Links repeater --------

	function initLinksRepeater() {
		var wrapper = $('#cta-links-wrapper');
		if (!wrapper.length) {
			return;
		}

		initSortable('#cta-links-wrapper');

		$('#cta-add-link').on('click', function (e) {
			e.preventDefault();
			var $row = $(
				'<div class="cta-link-row cta-sortable-row">' +
					'<span class="cta-drag-handle dashicons dashicons-move" title="Drag to reorder"></span>' +
					'<div class="cta-sortable-fields">' +
						'<input type="text" name="cta_links_url[]" placeholder="https://..." class="cta-link-url">' +
						'<input type="text" name="cta_links_label[]" placeholder="Display name (optional)" class="cta-link-label">' +
					'</div>' +
					'<button type="button" class="button cta-remove-link">Remove</button>' +
				'</div>'
			);
			wrapper.append($row);
		});

		wrapper.on('click', '.cta-remove-link', function (e) {
			e.preventDefault();
			$(this).closest('.cta-link-row').remove();
		});
	}

	// -------- Related Files (media frame) --------

	function initFilesField() {
		var wrapper = $('#cta-files-wrapper');
		var addBtn  = $('#cta-add-file');
		if (!wrapper.length || !addBtn.length || typeof wp === 'undefined' || !wp.media) {
			return;
		}

		initSortable('#cta-files-wrapper');

		var frame;

		addBtn.on('click', function (e) {
			e.preventDefault();

			if (frame) {
				frame.open();
				return;
			}

			frame = wp.media({
				title: 'Select or Upload Related Files',
				button: { text: 'Use these files' },
				multiple: true
			});

			frame.on('select', function () {
				var selection = frame.state().get('selection');
				selection.each(function (attachment) {
					attachment = attachment.toJSON();
					var id    = attachment.id;
					var title = attachment.title || attachment.filename;
					var url   = attachment.url;

					var html =
						'<div class="cta-file-row cta-sortable-row" data-id="' + id + '">' +
							'<span class="cta-drag-handle dashicons dashicons-move" title="Drag to reorder"></span>' +
							'<div class="cta-sortable-fields">' +
								'<input type="hidden" name="cta_files_id[]" value="' + id + '">' +
								'<span class="cta-file-row__label">' + $('<span>').text(title).html() + '</span> ' +
								'<a href="' + url + '" target="_blank" rel="noopener noreferrer">View</a>' +
								'<input type="text" name="cta_files_label[]" placeholder="Display name (optional)" class="cta-file-label">' +
							'</div>' +
							'<button type="button" class="button cta-remove-file">Remove</button>' +
						'</div>';

					wrapper.append(html);
				});
			});

			frame.open();
		});

		wrapper.on('click', '.cta-remove-file', function (e) {
			e.preventDefault();
			$(this).closest('.cta-file-row').remove();
		});
	}

	// -------- Related Videos repeater --------

	function initVideosRepeater() {
		var wrapper = $('#cta-videos-wrapper');
		if (!wrapper.length) {
			return;
		}

		initSortable('#cta-videos-wrapper');

		$('#cta-add-video').on('click', function (e) {
			e.preventDefault();
			var $row = $(
				'<div class="cta-video-row cta-sortable-row">' +
					'<span class="cta-drag-handle dashicons dashicons-move" title="Drag to reorder"></span>' +
					'<div class="cta-sortable-fields">' +
						'<input type="text" name="cta_videos_url[]" placeholder="https://www.youtube.com/watch?v=..." class="cta-video-url">' +
						'<input type="text" name="cta_videos_label[]" placeholder="Display name (optional)" class="cta-video-label">' +
					'</div>' +
					'<button type="button" class="button cta-remove-video">Remove</button>' +
				'</div>'
			);
			wrapper.append($row);
		});

		wrapper.on('click', '.cta-remove-video', function (e) {
			e.preventDefault();
			$(this).closest('.cta-video-row').remove();
		});
	}

	// -------- Sample Text repeater --------

	function initSampleTextsRepeater() {
		var wrapper = $('#cta-sample-texts-wrapper');
		if (!wrapper.length) {
			return;
		}

		$('#cta-add-sample-text').on('click', function (e) {
			e.preventDefault();

			var count = wrapper.find('.cta-sample-text-item').length + 1;

			var $item = $(
				'<div class="cta-sample-text-item" style="margin-bottom:1.5rem;border:1px solid #ddd;padding:0.75rem 0.75rem 1rem;background:#fafafa;">' +
					'<p><strong>Option ' + count + '</strong></p>' +
					'<textarea name="cta_sample_texts[]" rows="6" style="width:100%;"></textarea>' +
					'<p><button type="button" class="button cta-remove-sample-text">Remove Option</button></p>' +
				'</div>'
			);

			wrapper.append($item);
		});

		wrapper.on('click', '.cta-remove-sample-text', function (e) {
			e.preventDefault();
			$(this).closest('.cta-sample-text-item').remove();

			wrapper.find('.cta-sample-text-item').each(function (i) {
				$(this).find('p strong').first().text('Option ' + (i + 1));
			});
		});
	}

	// -------- Before form submit: sync all TinyMCE editors --------

	function syncAllEditors() {
		if (typeof tinymce !== 'undefined') {
			tinymce.triggerSave();
		}
	}

	$(document).ready(function () {
		initStepsRepeater();
		initLinksRepeater();
		initFilesField();
		initVideosRepeater();
		initSampleTextsRepeater();

		$('form#post').on('submit', function() {
			syncAllEditors();
		});
	});

})(jQuery);
