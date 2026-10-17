# Media Policy — WebP First

All photographic or other raster assets added to the APMC application should be stored and served as `.webp` by default.

- Use WebP for photos, commodity thumbnails, banners and other raster imagery.
- Keep functional icons and logos as SVG when they are truly vector artwork; converting SVG to WebP would reduce scaling quality without a useful size benefit.
- Do not add PNG/JPEG duplicates unless a specific compatibility requirement is documented.
- Use explicit width/height or aspect ratio for raster assets to avoid layout shift.
- Prefer lazy loading for below-the-fold images.

The v1.2.0 package itself contains no PNG/JPEG/GIF raster assets, so no conversion was required for this release.
