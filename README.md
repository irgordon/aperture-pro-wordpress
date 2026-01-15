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
## File Structure 

```
aperture-pro/
│
├── aperture-pro.php                     # Plugin bootstrap (initialization, cron, REST registration)
├── composer.json                        # Optional autoloading / dependencies
├── vendor/                              # Composer dependencies (if used)
│
├── src/
│   ├── Admin/
│   │   ├── AdminUI.php                  # Full admin settings UI (tooltips, validation, encryption)
│   │   ├── ThemeVariables.php           # Theme variable overrides for client portal
│   │   └── HealthCard.php               # Admin health dashboard (optional)
│   │
│   ├── ClientPortal/
│   │   ├── PortalController.php         # Portal routing, session binding, security
│   │   └── PortalRenderer.php           # Renders client-facing templates
│   │
│   ├── Config/
│   │   └── Config.php                   # Central config loader (reads encrypted settings)
│   │
│   ├── Download/
│   │   └── ZipStreamService.php         # Memory-safe ZIP streaming with rate limiting
│   │
│   ├── Email/
│   │   ├── EmailService.php             # Queued email sending, admin notifications
│   │   └── Templates/
│   │       ├── project-created.php
│   │       ├── proofs-ready.php
│   │       ├── proofs-approved.php
│   │       ├── editing-started.php
│   │       ├── final-gallery-ready.php
│   │       ├── otp-code.php
│   │       └── download-link.php
│   │
│   ├── Helpers/
│   │   ├── Crypto.php                   # AES-256/Sodium encryption for API keys & secrets
│   │   ├── Logger.php                   # Centralized logging
│   │   └── RateLimiter.php              # Token/IP-based rate limiting
│   │
│   ├── Proof/
│   │   └── ProofService.php             # Watermarking, low-res proof generation
│   │
│   ├── REST/
│   │   ├── BaseController.php           # Shared REST utilities
│   │   ├── AuthController.php           # Client session auth
│   │   ├── ClientProofController.php    # Proof selection, comments, approval
│   │   ├── AdminController.php          # Admin-side REST actions
│   │   ├── DownloadController.php       # Token-based downloads + OTP verification
│   │   ├── UploadController.php         # Chunked upload endpoints
│   │   └── PaymentController.php        # Webhook handler (decrypts secret)
│   │
│   ├── Services/
│   │   └── PaymentService.php           # Payment event processing
│   │
│   ├── Storage/
│   │   ├── StorageInterface.php         # Contract for all storage drivers
│   │   ├── StorageFactory.php           # Creates storage driver instances
│   │   ├── LocalStorage.php             # Local storage w/ signed URLs + path hiding
│   │   ├── CloudinaryStorage.php        # Cloudinary adapter
│   │   └── ImageKitStorage.php          # ImageKit adapter
│   │
│   ├── Upload/
│   │   ├── ChunkedUploadHandler.php     # Chunk session mgmt, assembly, integrity checks
│   │   └── Watchdog.php                 # Cleans abandoned uploads, updates Health Card
│   │
│   └── Health/
│       └── HealthService.php            # Tracks warnings/errors for admin visibility
│
├── assets/
│   ├── js/
│   │   ├── client-portal.js             # Full client-side uploader, proofs, OTP, downloads
│   │   └── admin-ui.js                  # Admin UI interactivity + AJAX tests
│   │
│   ├── css/
│   │   ├── client-portal.css            # Full portal UI
│   │   ├── client-portal.min.css        # Minified version
│   │   └── admin-ui.css                 # Admin settings UI
│   │
│   └── img/                             # Icons, placeholders, watermark overlays
│
├── templates/
│   ├── client/
│   │   ├── portal-header.php
│   │   ├── portal-dashboard.php
│   │   ├── portal-proofs.php
│   │   ├── portal-download.php
│   │   ├── portal-payment-alert.php
│   │   └── portal-footer.php
│   │
│   ├── email/                           # (Also mirrored under src/Email/Templates)
│   │   └── *.php
│   │
│   └── admin/
│       └── settings-page.php            # Full admin settings UI template
│
└── README.md                            # GitHub-ready documentation
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
