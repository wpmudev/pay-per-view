(function( $ ) {
	// Load plugin specific language pack
	tinymce.PluginManager.requireLangPack('payperview');

	tinymce.create('tinymce.plugins.PayPerViewPlugin', {
		/**
		 * Initializes the plugin, this will be executed after the plugin has been created.
		 * This call is done before the editor instance has finished it's initialization so use the onInit event
		 * of the editor instance to intercept that event.
		 *
		 * @param {tinymce.Editor} ed Editor instance that the plugin is initialized in.
		 * @param {string} url Absolute URL to where the plugin is located.
		 */
		init : function(ed, url) {
			// Register the command so that it can be invoked by using tinyMCE.activeEditor.execCommand('mceChat');
			ed.addCommand('mcePayPerView', function() {
				ed.windowManager.open({
					//file : url + "../../../../../wp-admin/admin-ajax.php?action=ppwTinymceOptions",
					width: 300,
					height: 200,
					inline : 1,
					id: 'plugin-slug-insert-dialog',
					body: [{
					    type: 'container',
					    layout: 'stack',
					    items: [
					      {type: 'label', id: 'ppw-description-label', text: ed.getLang( 'ppw_lang.description' ) },
					      {type: 'textbox', name: 'ppw-description', id: 'ppw-description', label: 'textbox', value: ''},
					      {type: 'label', text: ed.getLang( 'ppw_lang.price' )},
					      {type: 'textbox', name: 'ppw-price', id: 'ppw-price', label: 'textbox', value: ''}					      
					    ]
					  }],
					buttons: [{
						text: ed.getLang( 'ppw_lang.insert' ),
						id: 'plugin-slug-button-insert',
						class: 'insert',
						onclick: function( e ) {
							tinymce_ppw.insert_PPW_Shortcode( ed );
						},
					},
					{
						text: ed.getLang( 'ppw_lang.cancel' ),
						id: 'plugin-slug-button-cancel',
						onclick: 'close'
					}],
				}, {
					plugin_url : url // Plugin absolute URL
				});
			});

			// Register button
			ed.addButton('payperview', {
				title : ed.getLang('payperview.title'),
				cmd : 'mcePayPerView',
				image : url + '/payperview.png'
			});

			// Add a node change handler, selects the button in the UI when a image is selected
			ed.onNodeChange.add(function(ed, cm, n) {
				cm.setActive('payperview', n.nodeName == 'IMG');
			});
		},

		/**
		 * Creates control instances based in the incomming name. This method is normally not
		 * needed since the addButton method of the tinymce.Editor class is a more easy way of adding buttons
		 * but you sometimes need to create more complex controls like listboxes, split buttons etc then this
		 * method can be used to create those.
		 *
		 * @param {String} n Name of the control to create.
		 * @param {tinymce.ControlManager} cm Control manager to use inorder to create new control.
		 * @return {tinymce.ui.Control} New control instance or null if no control was created.
		 */
		createControl : function(n, cm) {
			return null;
		},

		/**
		 * Returns information about the plugin as a name/value array.
		 * The current keys are longname, author, authorurl, infourl and version.
		 *
		 * @return {Object} Name/value array containing information about the plugin.
		 */
		getInfo : function() {
			return {
				longname : 'Pay Per View',
				author : 'Hakan Evin',
				authorurl : 'http://premium.wpmudev.org',
				infourl : 'http://premium.wpmudev.org/project/pay-per-view',
				version : "1.0"
			};
		}
	});

	// Register plugin
	tinymce.PluginManager.add('payperview', tinymce.plugins.PayPerViewPlugin);

	tinymce_ppw = {

		insert_PPW_Shortcode: function( ed ){

			var selected_text = ed.selection.getContent();
			var description = $.trim($('#ppw-description').val());
			var price = $.trim($('#ppw-price').val());

			$( '.pw-error' ).remove();

			if ( ! price || isNaN( price ) ) {

				this.show_error_message( ed.getLang( 'ppw_lang.invalid_number' ) );
				jQuery('#ppw-price').focus();
				return false;

			}

			var id = Math.round((new Date()).getTime() / 1000) - 1330955000;

			var output = '[ppw id="' + id + '" description="' + description + '" price="' + price + '"]' + ed.selection.getContent() + '[/ppw]';

		    ed.execCommand('mceInsertContent', 0, output);
		    ed.windowManager.close();

		},

		show_error_message: function( msg ){
			
			var error_msg = $('<div>', {
				class: "pw-error",
				css: {           
				    color: "#E4717A",
				    fontSize: "1.1em",				    
				    width: "100%",
				    "text-align": "left",
				    border: "1px solid #E4717A",
				    "margin-bottom": "10px",
				    padding: "6px",
				    "white-space": "initial"
				  },
			});
			error_msg.text( msg );

			$( '#ppw-description-label' ).before( error_msg );
		}

	}

})(jQuery);
