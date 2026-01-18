# 📸 Aperture Pro  
**Photography Studio SaaS for Image Proofing, Download, and Gallery Management — powered by WordPress**

Aperture Pro is a modern, production‑grade WordPress plugin built for photography studios that need a secure, elegant, and scalable way to deliver proofs, collect approvals, and provide final downloads. It blends a polished client experience with a robust operational backend designed for reliability, observability, and long‑term maintainability.

---

## ✨ Features

### **Client Proofing**
- Watermarked, low‑resolution proof images  
- Image selection, commenting, and approval workflows  
- Signed, short‑lived proof URLs  
- Mobile‑friendly, accessible client portal  

### **Secure File Delivery**
- Download tokens bound to project, client email, and session  
- Optional OTP verification  
- Rate‑limited and single‑use token options  
- Signed URLs for local and cloud storage  

### **Chunked, Resumable Uploads**
- Client‑side chunked uploader with exponential backoff + jitter  
- Local session persistence for resume  
- Server‑side chunk assembly with watchdog cleanup  
- Progress polling and resumability  

### **Unified Upload Architecture**
- Provider‑agnostic `UploaderInterface` for all storage backends  
- Stream‑first uploads with automatic chunking or multipart fallback  
- Centralized retry strategy with exponential backoff  
- Explicit `UploadRequest` / `UploadResult` DTOs  
- Provider‑specific optimizations without Storage API changes  

### **Storage Adapters**
- Local storage with path‑hiding and signed URL proxying  
- S3 + CloudFront with multipart uploads  
- Cloudinary and ImageKit adapters  
- Extensible `StorageInterface` and `StorageFactory`  
- Batch existence checks + batch URL signing  

### **Payment Integration**
- **Payment Abstraction Layer** supporting multiple providers  
- Provider drivers (Stripe, PayPal, Square, Authorize.net, Amazon Pay)  
- Secure webhook verification  
- Normalized payment events via DTOs  
- Automatic project status updates via workflow engine  
- Admin “Payment Summary” card + timeline  

### **Workflow Engine**
- Idempotent project lifecycle transitions  
- Proof approval → editing → delivery state management  
- Payment‑driven state updates  
- Event‑driven email and notification hooks  
- Hardened against retries and webhook replay  

### **Admin UI**
- Modern SaaS‑style settings and Command Center  
- Tooltips, inline help, and validation  
- API key + webhook secret test actions  
- Theme variable overrides  
- Encrypted API keys and secrets at rest  

### **Observability & Safety**
- Centralized logging  
- Health Dashboard with modular cards  
- Queue depth and performance metrics  
- Queued admin email notifications  
- Watchdog for stuck uploads, proofs, and storage issues  

---

## 📁 Plugin File Structure

```
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
│   └── helpers.php
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
│   ├── Payments/
│   │   ├── DTO/
│   │   ├── Providers/
│   │   ├── PaymentProviderInterface.php
│   │   └── PaymentProviderFactory.php
│   ├── Proof/
│   ├── REST/
│   ├── Services/
│   ├── Storage/
│   │   ├── Upload/
│   │   │   ├── UploaderInterface.php
│   │   │   ├── UploadRequest.php
│   │   │   ├── UploadResult.php
│   │   │   ├── S3Uploader.php
│   │   │   ├── CloudinaryUploader.php
│   │   │   └── ImageKitUploader.php
│   │   └── StorageFactory.php
│   ├── Workflow/
│   └── Loader.php
│
├── assets/
│   ├── css/
│   ├── js/
│   └── images/
│
└── tests/
    ├── verify_uploaders.php
    ├── verify_payment_abstraction.php
    ├── benchmark_js_chunking.js
    └── phpunit.xml
```

---

## 🎨 Theme File Structure

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
   - Payment provider + webhook secret  
   - OTP requirements  
5. (Optional) Customize portal appearance under **Settings → Aperture Portal Theme**

---

## ⚙️ Quick Configuration Guide

### **Storage**
- **Local** — simplest; uses server disk  
- **S3 + CloudFront** — recommended for large ZIP deliveries  
- **Cloudinary / ImageKit** — optimized for image‑heavy proof galleries  

### **Email**
- Set a verified sender address for OTP + notifications  

### **Payments**
- Configure your provider to POST to:  
  ```
  https://your-site.com/wp-json/aperture/v1/webhooks/payment/{provider}
  ```
- Add your webhook secret  
- Validate via the built‑in test tool  

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
POST /aperture/v1/webhooks/payment/{provider}
GET  /aperture/v1/projects/{id}/payment-summary
GET  /aperture/v1/projects/{id}/payment-timeline
POST /aperture/v1/projects/{id}/retry-payment
```

---

## 🔐 Security

- Encryption at rest for API keys + secrets  
- Signed URLs for proofs + downloads  
- Optional OTP verification  
- Rate limiting for downloads and sensitive endpoints  
- Session + email binding for download tokens  
- REST middleware for request hygiene and abuse prevention  

---

## 🧩 Developer Notes

### **Client Assets**
- `client-portal.js` — uploader, proofs, OTP, downloads  
- SPA components for marketing and admin dashboards  

### **Server Components**
- Unified uploaders with retry + streaming  
- Proof generation queue with batch enqueueing  
- Payment Abstraction Layer  
- Workflow engine with idempotent transitions  
- REST controllers with middleware stack  
- Email queue + transactional delivery  

### **Extensibility**
- Add new storage providers via `UploaderInterface` + `StorageInterface`  
- Add new payment providers via `PaymentProviderInterface`  
- Add new admin cards via SPA component registry  

---

## 🧪 Troubleshooting

- **Proofs not generating** → check Imagick/GD  
- **Webhook failures** → verify signature header  
- **Upload issues** → check PHP limits and storage credentials  
- **Download errors** → verify token + OTP  

---

## 🤝 Contributing

Contributions welcome. High‑impact areas:

- Additional payment providers  
- Background ZIP generation  
- Redis‑backed rate limiting  
- Upload progress telemetry  
- End‑to‑end upload/download tests  

---

## 📄 License

MIT License — see `LICENSE` for details.
