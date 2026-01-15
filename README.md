# aperture-pro-wordpress
A full Photography Studio SaaS with CLient Portal for Image Proofing and Gallery Delivery

File Structure
```
aperture-pro/
│
├── aperture-pro.php
├── uninstall.php
│
├── src/
│   ├── Installer/
│   │   ├── Installer.php
│   │   ├── Schema.php
│   │   └── Activator.php
│   │
│   ├── Storage/
│   │   ├── StorageInterface.php
│   │   ├── LocalStorage.php
│   │   ├── ImageKitStorage.php
│   │   ├── CloudinaryStorage.php
│   │   └── StorageFactory.php
│   │
│   ├── Config/
│   │   ├── Config.php
│   │   ├── Defaults.php
│   │   └── Validator.php
│   │
│   ├── Auth/
│   │   ├── MagicLinkService.php
│   │   ├── TokenService.php
│   │   └── CookieService.php
│   │
│   ├── Workflow/
│   │   └── Workflow.php
│   │
│   ├── Admin/
│   │   └── AdminUI.php
│   │
│   ├── ClientPortal/
│   │   └── PortalController.php
│   │
│   ├── Upload/
│   │   └── ChunkedUploadHandler.php
│   │
│   ├── Download/
│   │   └── ZipStreamService.php
│   │
│   └── Helpers/
│       ├── Logger.php
│       ├── ErrorHandler.php
│       └── Utils.php
│
└── assets/
    ├── css/
    ├── js/
    └── img/
```

