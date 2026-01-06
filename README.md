# ⚡ Virtual Three-Phase Current Meter PWA

A photorealistic, web-based Progressive Web App (PWA) that visualizes real-time data from a physical smart meter. This project bridges the gap between raw digital data and a familiar hardware aesthetic.

![App Screenshot](images/svg_template_with_animation_font_b64.svg)

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
git clone https://github.com/jens62/virtual-3phase-meter.git

```


2. **Install Dependencies:**
This project uses Composer to manage libraries, including `tecnickcom/tc-lib-barcode` for barcode generation and `monolog/monolog` for structured logging. Install them using:
```bash
cd virtual-3phase-meter
composer install

```


3. **Set Permissions:**
The application needs to write to the project directory to save your configuration (`config.php`) and maintain debug logs. On Linux servers, you can grant the web server user (typically `www-data`) the necessary permissions:
```bash
sudo setfacl -R -m u:www-data:rwx /path/to/virtual-3phase-meter

```


4. **Configuration:**
There is no manual configuration file to rename. Simply navigate to `settings.php` in your web browser to initialize the `config.php` file and set up your meter's parameters.
5. **Deployment:**
Upload the project folder to your web server.
**Note:** Ensure the `vendor/` directory (created by Composer) is included in your upload, as it contains all required PHP libraries.

---
### ⚙️ Initial Configuration Workflow

After following the installation steps, use this workflow to get your meter online:

1. **Open Settings:** Navigate to `settings.php` in your web browser.
2. **Basic Connectivity:**
* Enter your **Host IP** (Tasmota device IP).
* Enter your **DataMatrix Content**. You can obtain this by scanning the QR-like code on your physical meter (e.g., using "Code Scan" on iPhone). Enter the non-hex part (e.g., `V1\r\nAA...`).
* Hit **Save & Apply**. This initializes your `config.php`.


3. **Define Display Metrics:**
* Scroll to the **Display Order & Precision** section.
* Click **Add New Line** to select which SML values (e.g., Total Consumption, Current Power) you want to visualize on the LCD.
* Set the **Label**, **Unit**, and **Precision** (decimals) for each row.
* Hit **Save** again to update your dashboard layout.


4. **Verify Data Flow:**
* Click **"Return to Dashboard"** at the bottom of the page to verify that data is flowing as expected.
* **Troubleshooting:** If data is not appearing, return to settings, set the **Log Level** to **Debug**, and check the `debug.log` file for detailed error messages.


5. **Optional UI Customization:**
* If the data flows correctly, further adjustment of the GUI by modifying `svg_template.xml` is **optional** and only necessary if you wish to change the visual aesthetic.


### 💡 Understanding the "Shadow" Effect

On the `settings.php` page, you will see an option for **Shadow**. This mimics the behavior of real LCD screens where inactive segments are still faintly visible as a background "8888".

* **How it works:** The dashboard layers two text elements. The "Shadow" is the bottom layer, showing all segments in a faint color to create a photorealistic depth effect.
* **Recommended Value:** A value of **0.1** (10% opacity) is usually ideal for a subtle, realistic look.
* **Disable:** Set this to **0** if you prefer a perfectly clean background with no visible inactive segments.

---
## 🎨 How to Customize the GUI
You can change the meter's appearance without touching the PHP code:
1.  Open `svg_template.xml` in **Inkscape**.
2.  Modify colors, textures, or layouts to match your specific hardware.
3.  **Crucial:** Ensure the `id` tags of the text elements (e.g., `id="power_val"`, `id="meter_id"`) remain consistent so the PHP script can inject the data.
4.  Save the file. Your PWA will automatically reflect the new design.

### 📂 Project Structure

* `virtual_meter.php`: The core dashboard and logic for the PWA display.
* `settings.php`: The web-based configuration interface to manage device settings and display metrics.
* `tasmota_utils.php`: Internal utility library for Tasmota discovery, DIN 43863-5 meter ID decoding, and Monolog integration.
* `config.php`: Your private system configuration (created automatically by `settings.php`; ignored by Git).
* `svg_template.xml`: The visual interface file containing the SVG meter design.
* `manifest.json`: Web app manifest for PWA installation.
* `assets/fonts/`: Contains the `digital-7 (mono).ttf` font for the display.

---
## ⚖️ License
This project is licensed under the **Apache License 2.0**. See the [LICENSE](LICENSE) file for details.