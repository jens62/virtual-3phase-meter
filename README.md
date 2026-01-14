
# ⚡ Virtual Three-Phase Current Meter PWA

A photorealistic, web-based Progressive Web App (PWA) that visualizes real-time data from a physical smart meter. This project bridges the gap between raw digital data and a familiar hardware aesthetic.

![App Screenshot](docs/images/svg_template_with_animation_font_b64.svg)

## 🌟 The Major Benefit
**No coding required for UI customization.** The interface is driven by a standard SVG file. Users can open the GUI file in any vector graphics editor (like **Inkscape**) to match the look of their specific physical meter. The PHP logic remains untouched while the visuals are completely swappable via SVG element IDs.

## ✨ Features
* **Photorealistic UI:** Nearly 1:1 visual representation of a real three-phase meter.
* **PWA Ready:** Installable on iOS, Android, or Desktop for a native app feel.
* **Dynamic Barcodes:** Uses `tc-lib-barcode` to render authentic meter serial numbers.
* **Highly Configurable:** Decoupled configuration logic from the visual presentation.

## 🛠 Tech Stack
* **PHP 8.0+**: Handles data logic and IR-interface integration.
* **SVG**: Provides the high-fidelity, scalable Graphical User Interface.
* **Composer**: Manages essential libraries for barcode generation.

---

## 🚀 Getting Started

### Prerequisites
* **Web Server:** A server with PHP 8.0 or higher installed.
* **Package Manager:** [Composer](https://getcomposer.org/) installed on your machine.
* **Hardware:** An IR-Reading head connected to your smart meter.

### Installation & Setup

1. **Clone the repository:**
```bash
git clone [https://github.com/jens62/virtual-3phase-meter.git](https://github.com/jens62/virtual-3phase-meter.git)

```

2. **Install Dependencies:**
This project uses Composer to manage libraries, including `tecnickcom/tc-lib-barcode` for barcode generation and `monolog/monolog` for structured logging. Install them using:

```bash
cd virtual-3phase-meter
composer install

```

3. **Set Permissions:**
The application needs to write to the `config/` directory to save your configuration and maintain debug logs. On Linux servers, grant the web server user (typically `www-data`) the necessary permissions:

```bash
sudo setfacl -R -m u:www-data:rwx /path/to/virtual-3phase-meter/config

```

🛠️ **Web Server Configuration (Apache)**

Inside your `<VirtualHost *:80>` block, add the following to point to the new `public` directory structure:

```apache
# This maps the URL path to the physical 'public' folder
Alias /virtual-3phase-meter "/var/www/html/virtual-3phase-meter/public"

<Directory "/var/www/html/virtual-3phase-meter/public">
    Options FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>

```

Restart Apache: `sudo systemctl restart apache2`

4. **Configuration:**
Navigate to `settings.php` in your web browser to initialize the `config.php` file (stored in the `/config` folder) and set up your meter's parameters.

---

### ⚙️ Initial Configuration Workflow

1. **Open Settings:** Navigate to `settings.php` in your web browser.
2. **Basic Connectivity:**

* Enter your **Host IP** (Tasmota device IP).
* Enter your **DataMatrix Content**. Enter the non-hex part (e.g., `V1\r\nAA...`).
* Click **Save Settings**. This initializes your configuration.

3. **Define Display Metrics:**

* Scroll to the **Display Order & Precision** section.
* Click **+ Add Metric** to create a new row using the dynamic template.
* Set the **Label**, **Unit**, and **Precision** (decimals) for each row.
* Click **Save Settings** again to update your dashboard layout.

4. **Verify Data Flow:**

* Click the **"Return to Dashboard →"** button at either the top or bottom of the page to verify data flow.
* **Troubleshooting:** If data is missing, set the **Log Level** to **Debug** in settings and check the `config/debug.log` file.

---

## 🎨 How to Customize the GUI

1. Copy a file from `public/assets/meter-templates/*.svg` into the same folder.
2. Open seettings, choose your newly created file from the drop-down list, and click **Save Settings**.
3. Adjust your SVG file using **Inkscape** or VS Code.
4. **Crucial:** Ensure the `id` tags (e.g., `id="svg-meter-id"`, `id="dynamic-rows"`) remain consistent so the PHP script can inject data.

| id in *svg | container for |
| --- | --- |
| `svg-meter-id` | meter id, e. g. 1 EBZ01 0000 0619 |
| `barcode-target` | the linear barcode of meter id |
| `matrixcode-target` | matrixcode |
| `status-text` | status at the top [OFFLINE |
| `last-update` | time of last update |
| `dynamic-rows` | the values in the "display" |

---

### 📂 Project Structure

* `public/index.php`: The core dashboard display (formerly `virtual_meter.php`).
* `public/settings.php`: The web-based configuration interface.
* `public/assets/css/settings.css`: Stylesheet for the settings GUI.
* `public/assets/js/`: JavaScript logic for dynamic row templates and UI interactions.
* `config/tasmota_utils.php`: Internal utility library for data fetching and logging.
* `config/config.php`: Your private system configuration (created automatically).
* `public/svg-meter-templates/`: Visual interface templates containing the SVG meter designs.

---

## ⚖️ License

This project is licensed under the **Apache License 2.0**.

```

```