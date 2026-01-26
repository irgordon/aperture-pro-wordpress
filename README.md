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

## 📐 Architectural Workflow

Aperture Pro follows a strict event-driven workflow to ensure data integrity and a smooth client experience.

### **1. Project Lifecycle & Uploads**
1.  **Project Creation**: Admin creates a project. The system initializes a **Proof Gallery** and generates a secure **Magic Link**.
2.  **High-Res Upload**: Photographer uploads high-resolution images via the admin panel.
    *   Uploads are **chunked** client-side and streamed to the server to bypass PHP memory limits.
    *   Chunks are assembled, validated (MIME/Size), and pushed to the configured **Storage Provider** (S3, Local, etc.).
    *   Metadata is stored in the `ap_images` table.
3.  **Proof Generation**: A background job (driven by WP-Cron and `ProofQueue`) automatically picks up new images.
    *   It downloads the original, resizes it, applies a watermark ("PROOF COPY"), and uploads the low-res variant.
    *   This ensures the original high-res file is never exposed directly to the client browser.

### **2. Client Interaction**
1.  **Access**: The client receives an email with the **Magic Link**, which logs them into the **Client Portal** (session-based).
2.  **Selection & Approval**:
    *   Clients view the watermarked proofs.
    *   They can "Heart" (Select) images and leave comments.
    *   Finally, they click "Approve Proofs," which locks the gallery and transitions the project status to `editing`.
3.  **Refinement**: The photographer sees the approved selection in the admin dashboard to perform final edits (retouching).

### **3. Payment & Delivery**
1.  **Payment**: The client pays for the package/session via the integrated payment gateway (Stripe/PayPal).
    *   Webhooks notify the **Payment Abstraction Layer**, which normalizes the event.
    *   `Workflow::onPaymentReceived` is triggered.
2.  **Delivery**:
    *   Upon confirmed payment (or manual trigger), the system generates a secure, time-bound **Download Token**.
    *   An email is sent to the client with a link to download their final gallery.
    *   Downloads are streamed through a signed URL proxy to protect the actual storage location.

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

## 🚀 First Installation & Setup

Follow these steps to get your studio up and running immediately after installing the plugin.

### **1. Installation**
1.  Upload the `aperture-pro` folder to your `/wp-content/plugins/` directory.
2.  Log in to WordPress Admin and navigate to **Plugins**.
3.  Click **Activate** under "Aperture Pro".
4.  *Optional*: If you are using the companion theme, upload and activate `aperture-pro-theme` under **Appearance → Themes**.

### **2. Storage Configuration (Crucial)**
*Go to **Aperture Pro → Settings → Storage**.*
*   **Local Storage**: Simplest for getting started. Files are stored on your server.
*   **S3 / Cloud Storage (Recommended)**: For production scalability.
    *   Select your provider (AWS S3, DigitalOcean Spaces, Wasabi, etc.).
    *   Enter your **Region**, **Bucket Name**, **Access Key**, and **Secret Key**.
    *   Click **Test Connection** to verify permissions.

### **3. Payment Setup**
*Go to **Aperture Pro → Settings → Payments**.*
1.  Select your provider (e.g., Stripe, PayPal).
2.  Enter your **API Keys** (Publishable/Secret).
3.  **Webhook Setup**:
    *   Copy the Webhook Endpoint URL displayed on the settings page (e.g., `https://site.com/wp-json/aperture/v1/webhooks/payment/stripe`).
    *   Add this endpoint in your Payment Provider's dashboard.
    *   Copy the **Webhook Secret** from the provider and paste it back into Aperture Pro settings.

### **4. Email & Notifications**
*Go to **Aperture Pro → Settings → Email**.*
*   Configure the **Sender Name** and **Sender Email**.
*   Ensure your WordPress install can send emails (use an SMTP plugin like WP Mail SMTP for reliability).
*   Test by sending a magic link to yourself.

### **5. Create Your First Project**
1.  Navigate to **Aperture Pro → Projects → Add New**.
2.  Enter a **Client Name** and **Email**.
3.  Set the **Project Title** (e.g., "Smith Family Session").
4.  Upload your first batch of images in the **Uploads** tab.
5.  Wait a moment for the **Proof Queue** to generate watermarked copies (check the **Health** dashboard if needed).
6.  Copy the **Magic Link** and open it in an Incognito window to see what your client sees.

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
