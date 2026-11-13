# Renders the WordPress.org banners and icons (and the PrestaShop logo) from banner.html and icon.html.
# Run in mcr.microsoft.com/playwright/python:v1.49.0-noble with fonts-roboto installed and this
# folder mounted at /brand; the PNGs land in /brand/out and are copied to .wordpress-org/.
from playwright.sync_api import sync_playwright
with sync_playwright() as p:
    b=p.chromium.launch()
    for scale, name in [(1,'banner-772x250.png'),(2,'banner-1544x500.png')]:
        pg=b.new_page(viewport={'width':772,'height':250}, device_scale_factor=scale)
        pg.goto('file:///brand/banner.html'); pg.wait_for_timeout(300)
        pg.screenshot(path='/brand/out/'+name)
    for size in [128,256]:
        pg=b.new_page(viewport={'width':256,'height':256}, device_scale_factor=size/256)
        pg.goto('file:///brand/icon.html'); pg.screenshot(path=f'/brand/out/icon-{size}x{size}.png', omit_background=True)
    for size, name in [(32,'logo.png'),(512,'logo-512x512.png')]:
        pg=b.new_page(viewport={'width':256,'height':256}, device_scale_factor=size/256)
        pg.goto('file:///brand/icon.html'); pg.screenshot(path='/brand/out/'+name, omit_background=True)
    b.close()
