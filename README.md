# 📸 Aperture Pro  
**Photography Studio SaaS for Image Proofing, Download, and Gallery Management — powered by WordPress**

Aperture Pro is a modern, production‑grade WordPress plugin built for photography studios that need a secure, elegant, and scalable way to deliver proofs, collect approvals, and provide final downloads. It blends a polished client experience with a robust operational backend designed for reliability, observability, and long‑term maintainability.

---

## ✨ Features

### **Client Proofing**
- Watermarked, low‑resolution proof images to deter unauthorized downloads  
- Image selection, commenting, and approval workflows  
- Signed, short‑lived proof URLs  
- Mobile‑friendly, accessible client portal  

### **Secure File Delivery**
- Download tokens bound to project, client email, and session  
- Optional OTP verification for sensitive deliveries  
- Rate‑limited and single‑use token options  
- Signed URLs for local and cloud storage  

### **Chunked, Resumable Uploads**
- Client‑side chunked uploader with exponential backoff + jitter  
- Local session persistence for resume after network interruptions  
- Server‑side chunk assembly with watchdog cleanup  
- Progress polling and resumability  

### **Storage Adapters**
- Local storage with path‑hiding and signed URL proxying  
- Cloudinary + ImageKit adapters with HTTPS and signed URLs  
- Extensible `StorageInterface` and `StorageFactory`  

### **Payment Integration**
- Webhook handler for payment providers  
- Secure signature verification  
- Automatic project status updates  
- Download token generation on successful payment  

### **Admin UI**
- Modern SaaS‑style settings page  
- Tooltips, inline help, and validation  
- API key + webhook secret test actions  
- Theme variable overrides for branding  
- Secure encryption of API keys and secrets at rest  

### **Observability & Safety**
- Centralized logging  
- Health Card surfacing warnings and critical errors  
- Queued admin email notifications (rate‑limited)  
- Watchdog for stuck uploads and storage issues  

---
## Plugin File Structure 

aperture-pro/
│
├── aperture-pro.php
├── index.php
├── README.md
├── CHANGELOG.md
├── composer.json
├── package.json
│
├── inc/
│   ├── autoloader.php
│   ├── helpers.php
│
├── src/
│   ├── Admin/
│   ├── Auth/
│   ├── ClientPortal/
│   ├── Config/
│   ├── Download/
│   ├── Email/
│   ├── Health/
│   ├── Helpers/
│   ├── Installer/
│   ├── Proof/
│   ├── REST/
│   ├── Services/
│   ├── Storage/
│   ├── Upload/
│   ├── Workflow/
│   └── Loader.php
│
├── assets/
│   ├── css/
│   │   ├── admin.css
│   │   ├── health.css
│   │   └── cards/
│   │       └── performance.css         # NEW — performance card styles
│   │
│   ├── js/
│   │   ├── client-portal.js
│   │   └── spa/
│   │       ├── index.js
│   │       ├── bootstrap.js            # UPDATED — registers components + handles SPA routing
│   │       ├── components/
│   │       │   ├── hero.js
│   │       │   ├── features.js
│   │       │   ├── pricing.js
│   │       │   ├── testimonials.js
│   │       │   ├── faq.js
│   │       │   ├── cta.js
│   │       │   ├── PerformanceCard.js  # NEW — live performance card
│   │       │   ├── StorageCard.js      # NEW — live storage card
│   │       │   └── HealthDashboard.js  # NEW — auto-registers all cards
│   │       │
│   │       └── hooks/
│   │           ├── usePerformanceMetrics.js  # NEW — performance hook
│   │           └── useStorageMetrics.js      # NEW — storage hook
│   │
│   └── images/
│
└── tests/
    ├── verify_theme_load.php
    ├── benchmark_js_chunking.js
    └── phpunit.xml

```
---
## Theme File Structure
```
aperture-pro-theme/
│
├── style.css
├── theme.json
├── screenshot.png
│
├── functions.php
├── index.php
│
├── inc/
│   ├── enqueue.php
│   ├── template-tags.php
│   └── helpers.php
│
├── parts/
│   ├── header.html
│   ├── footer.html
│   └── navigation.html
│
├── templates/
│   ├── front-page.html
│   ├── single.html
│   └── page.html
│
├── assets/
│   ├── css/
│   │   ├── header.css
│   │   ├── navigation.css
│   │   └── layout.css
│   │
│   ├── js/
│   │   ├── theme.js
│   │   └── interactions.js
│   │
│   └── images/
│
└── tests/
    └── verify_theme_load.php
```

---
## 🚀 Installation

1. Upload the plugin to `wp-content/plugins/`  
2. Activate it in **WordPress Admin → Plugins**  
3. Open **Aperture Pro → Settings**  
4. Configure:
   - Storage driver  
   - Cloud provider API keys  
   - Email sender  
   - Webhook secret  
   - OTP requirements  
5. (Optional) Customize portal appearance under **Settings → Aperture Portal Theme**

---

## ⚙️ Quick Configuration Guide

### **Storage**
- **Local**: simplest; uses server disk with signed URL proxying  
- **Cloudinary / ImageKit**: recommended for large galleries; offloads bandwidth  

### **Email**
- Set a verified sender address for OTP and download notifications  

### **Payments**
- Configure your payment provider to POST to:  
  ```
  https://your-site.com/wp-json/aperture/v1/webhooks/payment
  ```
- Add your webhook secret (encrypted at rest)  
- Use the **Validate** button to confirm format  

### **OTP**
- Enable OTP for secure downloads  
- Clients receive a short‑lived code via email  

---

## 🔌 REST API Endpoints (Selected)

### **Uploads**
```
POST /aperture/v1/uploads/start
POST /aperture/v1/uploads/{upload_id}/chunk
GET  /aperture/v1/uploads/{upload_id}/progress
```

### **Proofing**
```
GET  /aperture/v1/projects/{project_id}/proofs
POST /aperture/v1/proofs/{gallery_id}/select
POST /aperture/v1/proofs/{gallery_id}/comment
POST /aperture/v1/proofs/{gallery_id}/approve
```

### **Downloads**
```
POST /aperture/v1/projects/{project_id}/regenerate-download-token
GET  /aperture/v1/download/{token}
POST /aperture/v1/download/{token}/request-otp
POST /aperture/v1/download/verify-otp
```

### **Payments**
```
POST /aperture/v1/webhooks/payment
```

---

## 🔐 Security

- **Encryption at rest** for API keys + webhook secrets  
- **Signed URLs** for proofs and downloads  
- **OTP verification** (optional)  
- **Rate limiting** for downloads and admin notifications  
- **Session + email binding** for download tokens  
- **Watchdog** for stuck uploads and storage failures  

---

## 🧩 Developer Notes

### **Client Assets**
- `assets/js/client-portal.js` — uploader, proofs, OTP, downloads  
- `assets/css/client-portal.css` — portal UI (minified version included)

### **Server Components**
- `src/Upload/ChunkedUploadHandler.php`  
- `src/Proof/ProofService.php`  
- `src/Helpers/Crypto.php`  
- `src/Admin/AdminUI.php`  
- `src/REST/*` controllers  
- `src/Email/EmailService.php`  

### **Extensibility**
- Add new storage adapters by implementing `StorageInterface`  
- Add new email templates under `templates/email/`  
- Add new REST endpoints under `src/REST/`  

---

## 🧪 Troubleshooting

- **Proofs not generating** → check Imagick/GD availability  
- **Webhook failures** → verify secret + signature header  
- **Upload issues** → check PHP limits (`upload_max_filesize`, `post_max_size`)  
- **Download errors** → verify token validity + OTP status  

---

## 🤝 Contributing

Pull requests are welcome. Areas that benefit from contributions:

- Provider‑specific API key validation  
- Background ZIP generation  
- Redis‑backed rate limiting  
- End‑to‑end tests for upload/download flows  

---

## 📄 License

MIT License — see `LICENSE` for details.

---
