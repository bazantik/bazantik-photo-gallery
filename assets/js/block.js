 (function (blocks, element, components, blockEditor, apiFetch, i18n) {
     const __ = i18n.__;
     const el = element.createElement;
     const { registerBlockType } = blocks;
     const { SelectControl, PanelBody, Spinner } = components;
     const { InspectorControls, useBlockProps } = blockEditor;
     const { useState, useEffect } = element;

     registerBlockType('bazantik-photo-gallery/album-embed', {
         title: __('Bazantik Photo Gallery album', 'bazantik-photo-gallery'),
         icon: 'format-gallery',
         category: 'media',
         attributes: {
             albumId: {
                 type: 'integer',
                 default: 0
             }
         },
         edit: function (props) {
             const albumId = props.attributes.albumId;
             const blockProps = useBlockProps({ style: { padding: '24px', border: '1px dashed #2271b1', borderRadius: '4px', textAlign: 'center', background: '#f0f6fc' } });
             const [albums, setAlbums] = useState(null);

             useEffect(() => {
                 apiFetch({ path: '/bazantik-photo-gallery/v1/albums' })
                     .then((data) => setAlbums(data))
                     .catch(() => setAlbums([]));
             }, []);

             const options = [{ value: 0, label: __('— Select an album —', 'bazantik-photo-gallery') }];
             if (albums) {
                 albums.forEach((a) => {
                     options.push({ value: a.id, label: a.title });
                 });
             }

             function onChangeAlbumId(newId) {
                 props.setAttributes({ albumId: parseInt(newId, 10) || 0 });
             }

             return el(
                 element.Fragment,
                 null,
                 el(
                     InspectorControls,
                     null,
                     el(
                         PanelBody,
                         { title: __('Album settings', 'bazantik-photo-gallery'), initialOpen: true },
                         albums === null
                             ? el(Spinner)
                             : el(SelectControl, {
                                     label: __('Select an album', 'bazantik-photo-gallery'),
                                     value: albumId,
                                     options: options,
                                     onChange: onChangeAlbumId,
                               })
                     )
                 ),
                 el(
                     'div',
                     blockProps,
                     el('strong', { style: { fontSize: '15px' } }, '📸 ' + __('Bazantik Photo Gallery album', 'bazantik-photo-gallery')),
                     el('p', null, albumId ? __('Selected album:', 'bazantik-photo-gallery') + ' ' + (options.find(o => o.value === albumId)?.label || albumId) : __('Choose an album to display in the right-hand sidebar.', 'bazantik-photo-gallery'))
                 )
             );
         },
         save: function () {
             return null;
         }
     });
 })(window.wp.blocks, window.wp.element, window.wp.components, window.wp.blockEditor, window.wp.apiFetch, window.wp.i18n);
