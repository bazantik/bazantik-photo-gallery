=== Bazantik Photo Gallery ===
Contributors: bazantik
Donate link: https://ko-fi.com/bazantik
Tags: gallery, photo gallery, photography, albums, exif
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self-hosted photo gallery with folders, albums and private albums, full-screen lightbox, EXIF, maps and background processing of large uploads.

== Description ==

Publish photo albums on your own WordPress site and organize them in folders. Justified grid, full-screen lightbox with zoom and slideshow, EXIF data, location map, password / secret-link albums and folders, background processing of large uploads.

Live demo: https://bazantik.eu/bazantik-folder/demo-folder/

= External services =

When the visitor clicks "Show map", the visitor's browser loads map tiles from OpenStreetMap (https://tile.openstreetmap.org) by default, or from another tile provider if the site administrator sets one in Settings. If that provider fails, the visitor can choose to switch to OpenStreetMap; only then is the request sent there. The tile provider thus receives the visitor's IP address, the requested tile coordinates and the page address (Referer). No data leaves the site before the visitor opens a map.

Providers selectable in Settings (each uses the administrator's own account and API key; the key is part of the tile address and therefore visible in the page source, so restrict it to your domain where the provider allows it):

* OpenStreetMap (tile.openstreetmap.org) - terms: https://wiki.osmfoundation.org/wiki/Terms_of_Use, privacy: https://osmfoundation.org/wiki/Privacy_Policy
* Mapy.com (api.mapy.com; the provider's logo is loaded from the same domain) - terms: https://developer.mapy.com/terms-and-conditions/, privacy: https://o-seznam.cz/pravni-informace/ochrana-udaju/
* Thunderforest (tile.thunderforest.com) - terms: https://www.thunderforest.com/terms/, privacy: https://www.thunderforest.com/privacy/
* Stadia Maps (tiles.stadiamaps.com) - terms: https://stadiamaps.com/terms-of-service/, privacy: https://stadiamaps.com/privacy/privacy-policy/
* MapTiler (api.maptiler.com; the provider's logo is loaded from the same domain) - terms: https://www.maptiler.com/terms/, privacy: https://www.maptiler.com/privacy-policy/
* Mapbox (api.mapbox.com) - terms: https://www.mapbox.com/legal/tos, privacy: https://www.mapbox.com/legal/privacy
* A custom tile server entered by the administrator.

= Privacy =

* The plugin does not track visitors and sets no analytics or advertising cookies.
* Unlocking a password-protected album or folder, or opening a hidden folder by its secret link, sets a signed cookie (`wp-postpass_bazantik_*`) for 10 days. Optional limiting of password attempts (off by default) stores only a keyed hash of the visitor's IP address, for 10 minutes by default.
* The visitor's browser stores viewer preferences (slideshow speed, panel state, lightbox appearance, map consent, selected map layer) in `localStorage`; nothing is sent to the server.
* EXIF data is read and stored only for photos uploaded to an album (or added to an album later); other uploads of your site are left untouched. GPS coordinates are printed into a page only when the album's map is enabled. When it is disabled, the Download button serves JPEG originals with the GPS fields removed from the standard EXIF and XMP data; a file opened by its direct URL still contains all metadata.
* Photos of albums that are not public stay in the Media Library when the album is deleted.
* The plugin suggests wording for your privacy policy (Settings → Privacy).

= Bundled libraries =

Leaflet 1.9.4 (BSD 2-Clause License), https://leafletjs.com (source code: https://github.com/Leaflet/Leaflet/tree/v1.9.4)

== Installation ==

1. Upload the `bazantik-photo-gallery` folder to `/wp-content/plugins/` or install the ZIP via *Plugins → Add New → Upload Plugin*.
2. Activate the plugin in *Plugins → Installed Plugins*.
3. Open *Bazantik Photo Gallery → Add New*, drag your photos into the upload zone and publish the album.
4. Show the album with the shortcode `[bazantik_album id="123"]`, the "Bazantik Photo Gallery album" block, or the album's own address.

== Frequently Asked Questions ==

= Why do some photos have no EXIF data or resized versions? =

Upload photos with the uploader in the album editor: it creates the optimized sizes used by the gallery (4K or 2560 px as set in Settings, 2048 px, 768 px, thumbnails) and reads EXIF data. Photos added by FTP or to the Media Library by other means do not get these sizes; when you add such a photo to an album with the “Add photos and videos from the media library” button, its EXIF data is read and saved with the album.

= Is OpenStreetMap a good choice for the map background on a busy site? =

OpenStreetMap's public tile servers are free for light use but come without any guarantee and have a [usage policy](https://operations.osmfoundation.org/policies/tiles/). For a busy site choose a tile provider in Settings (presets for Mapy.com, Thunderforest, Stadia Maps, MapTiler and Mapbox, or your own tile URL) and enter your API key. The key is part of the tile URL and therefore visible in the page source, so use a key meant for public (browser) use and, where the provider allows it, restrict it to your domain in the provider's dashboard (not every provider offers this; with Mapbox use a public `pk.` token, never a secret one). Check the provider's usage limits and terms: free plans may be for non-commercial use only, may require a logo, and some layers (for example satellite) may need a paid plan. If the selected provider offers more than one layer, the visitor can switch layers on the map (this can be turned off in Settings). If the provider stops delivering tiles, the visitor sees a notice and can switch the map to OpenStreetMap.

= Does the plugin work without a map provider account? =

Yes. The default map tiles come from OpenStreetMap; a different tile provider can be set in Settings.

= What browsers and devices are supported? =

The gallery and lightbox work in current Safari, Chrome, Edge, Firefox and Samsung Internet on macOS, Windows, Linux, iOS/iPadOS and Android; older browsers get simpler fallbacks. Videos are not converted: a HEVC (H.265) video from a phone may not play in Chrome on Windows or in Firefox; use H.264 (MP4) if you need playback everywhere.

= Which file formats can I upload? =

JPEG (full support and fast upload), PNG, WebP, AVIF, GIF and HEIC/HEIF (WordPress 6.7+ converts HEIC to JPEG when the server supports it), plus MOV, MP4, M4V and WebM videos. RAW files (for example NEF) are not supported. JPEGs uploaded to an album are processed in the background: the upload stays fast and the 4K (or 2560 px) and 2048 px versions are created afterwards.

= Why do photos uploaded from an Android phone have no location? =

Chrome for Android removes the GPS location from a photo before a web page receives it, so the plugin cannot read it. In testing, Firefox, Opera and Samsung Internet on Android kept it. To keep the location, upload from one of those browsers or from a computer. Photos without a location are simply shown without a map.

= How do I change the order of albums and folders in the overview? =

Open *Bazantik Photo Gallery → Order of albums and folders*, choose the level (the top level or a folder) and pick an order: by name, newest or oldest first, or manual (drag the items by the handle or use the arrows). Every folder has its own order; “Apply the change to nested folders” copies a saved non-manual order to them. Items added later appear at the end of a manual order.

= Why is a photo still "waiting" after the upload? =

Background processing is run by WP-Cron, which needs visits to your site. On a quiet site add a real server cron job that opens `https://your-site/wp-cron.php` every hour (the plugin also continues processing by itself after a batch while there is work left). You can watch and control the queue in *Bazantik Photo Gallery → Photo processing* and pause it there.

= Who can see private albums and their metadata? =

Albums set to "unlisted" are excluded from archives, search, feeds, the sitemap and previous/next links. Password-protected albums are also excluded from search, feeds, the sitemap and previous/next links, and appear in gallery overviews only as locked cards without a cover or photo counts. Neither kind is indexed or cached by page-cache plugins. Files uploaded into an album get random file names and attachment pages return 404. A person who knows the direct URL of an image file can still open it; for sensitive photos do not rely on file names.

= What happens when I uninstall the plugin? =

Only settings, scheduled tasks and stored folder passwords are removed. Albums, photos, videos and their metadata stay in your database and Media Library so that reinstalling does not lose data.

== Screenshots ==

1. Album page with a justified photo grid that keeps every photo's original proportions.
2. Lightbox with a top toolbar (info, fullscreen, slideshow, download, close, collapse) and navigation arrows.
3. Lightbox side panel with the photo's EXIF data (camera, lens, exposure settings, date).
4. The same side panel with a map showing where the photo was taken.
5. Lightbox settings (shown with the toolbar collapsed): slideshow speed and loop, navigation arrows, auto-hiding controls, and a translucent, optionally blurred background.
6. Gallery archive with folders (each folder cover is a collage of its album covers) and albums.
7. Photos being processed in the background: finished photos are shown, the rest appear as blurred placeholders and fill in automatically.
8. Album editor with drag and drop upload, sorting, cover selection and visibility settings.
9. Plugin settings page.

== Changelog ==

= 1.0.0 =
* First public release.
