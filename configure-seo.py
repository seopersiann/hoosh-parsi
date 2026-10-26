#!/usr/bin/env python3
"""Optional domain/metadata helper. The exported website has no build or runtime dependency."""
import argparse
import html
import json
import re
from pathlib import Path
from urllib.parse import urlsplit

SOCIALS = [
    'http://t.me/s/hooshmandparsi_blog',
    'https://www.instagram.com/hooshmandparsi/',
    'https://www.linkedin.com/company/hooshmand-parsi/',
    'https://wa.me/989221612426',
    'https://www.facebook.com/hooshmandparsi'
]

def business_data(origin):
    return {
        '@type': 'LocalBusiness', '@id': origin+'/#organization',
        'name': 'هوشمند پارسی', 'legalName': 'جستجوی هوشمند پارسی',
        'url': origin+'/', 'logo': origin+'/assets/favicon.svg',
        'image': origin+'/assets/favicon.svg',
        'description': 'طراحی وب‌سایت، فروشگاه اینترنتی، سئو و پشتیبانی سایت برای کسب‌وکارهای شیراز',
        'telephone': '+989221612426', 'email': 'hello@hooshmandparsi.ir',
        'address': {'@type':'PostalAddress', 'streetAddress':'بلوار پاسداران، ساختمان اداری، طبقهٔ چهارم، واحد ۴۰۱', 'addressLocality':'شیراز', 'addressRegion':'فارس', 'addressCountry':'IR'},
        'areaServed': {'@type':'City', 'name':'شیراز'},
        'openingHoursSpecification': [{'@type':'OpeningHoursSpecification', 'dayOfWeek':['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'], 'opens':'09:00', 'closes':'17:00'}],
        'contactPoint': [
            {'@type':'ContactPoint', 'telephone':'+989221612426', 'contactType':'customer service', 'availableLanguage':['fa'], 'url':'https://wa.me/989221612426'},
            {'@type':'ContactPoint', 'telephone':'+982191097794', 'contactType':'customer service', 'availableLanguage':['fa']}
        ],
        'hasMap':'https://www.google.com/maps/search/?api=1&query=JFFJ%2BP99%20Pasdaran%20Blvd%20Shiraz%20Fars%20Province',
        'priceRange':'طراحی سایت از ۱۸ تا ۵۰ میلیون تومان', 'sameAs':SOCIALS
    }

def rebase_schema_urls(value, old_origins, origin, key=None):
    if key in ('contentUrl', 'thumbnailUrl', 'embedUrl'):
        return value
    if isinstance(value, dict):
        return {k: rebase_schema_urls(v, old_origins, origin, k) for k,v in value.items()}
    if isinstance(value, list):
        return [rebase_schema_urls(v, old_origins, origin, key) for v in value]
    if isinstance(value, str):
        for old in old_origins:
            if value == old or value.startswith(old+'/'):
                if not value.startswith(old+'/wp-content/'):
                    return origin+value[len(old):]
    return value

def configure(site_url, root):
    parsed = urlsplit(site_url)
    if parsed.scheme not in ('http','https') or not parsed.hostname or '.' not in parsed.hostname or parsed.username or parsed.password or parsed.query or parsed.fragment or parsed.path not in ('', '/') or parsed.port:
        raise ValueError('Use the production HTTP or HTTPS origin, with no path, port, credentials or parameters.')
    origin = f'{parsed.scheme}://{parsed.hostname.encode("idna").decode("ascii")}'
    pages = {'index.html':''}
    pages.update({path.name:path.name for path in sorted(root.glob('*.html')) if path.name != 'index.html'})
    for filename, suffix in pages.items():
        path = root/filename
        source = path.read_text(encoding='utf-8')
        canonical = f'{origin}/{suffix}'
        previous = re.search(r'<link rel="canonical" href="([^"]+)"',source)
        old_origins = {'https://hooshmandparsi.ir','http://hooshmandparsi.ir'}
        if previous:
            previous_url = urlsplit(html.unescape(previous.group(1)))
            old_origins.add(f'{previous_url.scheme}://{previous_url.netloc}')
        project_images = {
            'article-tablo-decor-case-study.html': ('assets/tablo-decor-storefront.png',1265,889,'تصویر واقعی فروشگاه تابلو دکور'),
            'project-tablo-decor.html': ('assets/tablo-decor-storefront.png',1265,889,'تصویر واقعی فروشگاه تابلو دکور'),
            'project-new-bikini.html': ('assets/new-bikini-visual.webp',1200,675,'تصویر معرفی مجموعهٔ لوازم شنا از سایت نیو بیکینی'),
            'project-shiraz-yadak.html': ('assets/shiraz-yadak-storefront.png',1265,712,'تصویر واقعی فروشگاه شیراز یدک'),
            'project-perfume-shop.html': ('assets/perfume-storefront.png',1253,705,'تصویر پیش‌نمایش فروشگاه عطر و ادکلن'),
            'project-nasim-gold.html': ('assets/nasim-gold-storefront.png',1265,712,'تصویر واقعی سایت نسیم گلد')
        }
        social_image,social_width,social_height,social_alt = project_images.get(filename,('assets/office-laptop.jpg',1536,1024,'تصویر مفهومی لپ‌تاپ با هویت بصری سرمه‌ای و آبی هوشمند پارسی'))
        source = re.sub(r'\s*<!-- production-seo:start -->.*?<!-- production-seo:end -->', '', source, flags=re.S)
        block = '\n'.join([
            '  <!-- production-seo:start -->',
            f'  <link rel="canonical" href="{html.escape(canonical,quote=True)}">',
            f'  <meta property="og:url" content="{html.escape(canonical,quote=True)}">',
            f'  <meta property="og:image" content="{origin}/{social_image}">',
            f'  <meta property="og:image:width" content="{social_width}">',
            f'  <meta property="og:image:height" content="{social_height}">',
            f'  <meta property="og:image:alt" content="{social_alt}">',
            f'  <meta name="twitter:image" content="{origin}/{social_image}">',
            '  <!-- production-seo:end -->'
        ])
        source=source.replace('</head>',block+'\n</head>')
        match=re.search(r'<script type="application/ld\+json">(.*?)</script>',source,re.S)
        schema=rebase_schema_urls(json.loads(match.group(1)),old_origins,origin)
        graph=[entry for entry in schema['@graph'] if entry['@type'] not in ('Organization','LocalBusiness','WebSite','BreadcrumbList')]
        title=re.search(r'<title>(.*?)</title>',source).group(1)
        for entry in graph:
            if entry['@type'] in ('WebPage','AboutPage','ContactPage','CollectionPage'):
                entry.update({'@id':canonical+'#webpage','url':canonical,'isPartOf':{'@id':origin+'/#website'},'publisher':{'@id':origin+'/#organization'}})
            elif entry['@type']=='Service':
                entry.update({'@id':canonical+'#service','url':canonical,'provider':{'@id':origin+'/#organization'}})
            elif entry['@type']=='VideoObject':
                entry.update({'@id':origin+'/#web-design-video','publisher':{'@id':origin+'/#organization'},'url':origin+'/video-web-design.html'})
            elif entry['@type']=='CreativeWork':
                entry.update({'creator':{'@id':origin+'/#organization'}})
            elif entry['@type']=='Article':
                entry.update({'@id':canonical+'#article','url':canonical,'mainEntityOfPage':{'@id':canonical+'#webpage'},'author':{'@id':origin+'/#organization'},'publisher':{'@id':origin+'/#organization'}})
        graph.append(business_data(origin))
        graph.append({'@type':'WebSite','@id':origin+'/#website','url':origin+'/','name':'هوشمند پارسی','inLanguage':'fa-IR','publisher':{'@id':origin+'/#organization'}})
        if filename != 'index.html':
            items=[{'@type':'ListItem','position':1,'name':'خانه','item':origin+'/'}]
            if filename.startswith('article-'):
                items.append({'@type':'ListItem','position':2,'name':'مقالات و راهنماها','item':origin+'/articles.html'})
            if filename.startswith('project-'):
                items.append({'@type':'ListItem','position':2,'name':'نمونه‌های طراحی','item':origin+'/#portfolio'})
            if filename.startswith('service-'):
                items.append({'@type':'ListItem','position':2,'name':'خدمات طراحی سایت','item':origin+'/#services'})
            items.append({'@type':'ListItem','position':len(items)+1,'name':title.split(' | ')[0],'item':canonical})
            graph.append({'@type':'BreadcrumbList','itemListElement':items})
        schema['@graph']=graph
        source=source[:match.start(1)]+json.dumps(schema,ensure_ascii=False)+source[match.end(1):]
        if filename.startswith('article-'):
            source=source.replace('<meta property="og:type" content="website">','<meta property="og:type" content="article">')
        path.write_text(source,encoding='utf-8')
    urls='\n'.join(f'  <url><loc>{html.escape(origin+"/"+suffix)}</loc></url>' for name,suffix in pages.items() if not name.startswith('demo-'))
    (root/'sitemap.xml').write_text('<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n'+urls+'\n</urlset>\n',encoding='utf-8')
    (root/'robots.txt').write_text(f'User-agent: *\nAllow: /\n\nSitemap: {origin}/sitemap.xml\n',encoding='utf-8')
    return origin

if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('site_url',nargs='?',default='http://hoosh-parsi.ir',help='Production HTTP or HTTPS origin')
    args=parser.parse_args()
    try: print('SEO and LocalBusiness configured for '+configure(args.site_url,Path(__file__).resolve().parent))
    except ValueError as error: parser.error(str(error))
