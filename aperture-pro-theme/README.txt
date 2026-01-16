aperture-pro-theme/
├── style.css                       (theme header)
├── theme.json                      (design tokens + block settings)
├── functions.php                   (theme bootstrap)
│
├── inc/
│   ├── enqueue.php                 (SPA + CSS loading)
│   ├── blocks.php                  (pattern registration)
│   ├── spa.php                     (hydration mapping)
│   ├── seo.php                     (SEO plugin compatibility)
│   └── ...
│
├── templates/
│   ├── index.html
│   ├── front-page.html
│   ├── page.html
│   ├── single.html
│   ├── archive.html
│   ├── 404.html
│   │
│   └── parts/
│       ├── header.html
│       ├── footer.html
│       ├── hero.html
│       ├── features.html
│       ├── pricing.html
│       ├── testimonials.html
│       ├── faq.html
│       └── cta.html
│
├── patterns/
│   ├── hero.php
│   ├── features.php
│   ├── pricing.php
│   ├── testimonials.php
│   ├── faq.php
│   └── cta.php
│
├── assets/
│   ├── css/
│   │   ├── tokens.css
│   │   ├── frontend.css
│   │   ├── blocks.css
│   │   ├── spa.css
│   │   └── animations.css
│   │
│   ├── js/
│   │   ├── frontend.js
│   │   └── spa/
│   │       ├── index.js
│   │       ├── bootstrap.js
│   │       ├── core/
│   │       ├── components/
│   │       ├── pages/
│   │       └── ui/
│   │
│   └── images/
│
└── screenshot.png                  (theme preview)
