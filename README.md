# Payment Verification API

A simple Laravel API for verifying payment transactions from Ethiopian banks (CBE, BOA, Telebirr) using direct API calls and image processing with OCR.

## Quick Start

1. **Install dependencies:**

    ```bash
    composer install
    ```

2. **Setup environment:**

    ```bash
    cp .env.example .env
    php artisan key:generate
    ```

3. **Add your Gemini API key to `.env`:**

    ```env
    GEMINI_API_KEY=your-actual-api-key-here
    ```

4. **Start the server:**
    ```bash
    php artisan serve
    ```

## API Endpoints

-   `GET /` - API overview
-   `POST /api/cbe/verify` - CBE verification
-   `POST /api/boa/verify` - BOA verification
-   `POST /api/telebirr/verify` - Telebirr verification
-   `POST /api/image/cbe/verify` - CBE image verification
-   `POST /api/image/boa/verify` - BOA image verification
-   `POST /api/image/telebirr/verify` - Telebirr image verification

## Requirements

-   PHP 8.2+
-   Composer
-   Chrome/Chromium (for Telebirr)
-   Gemini API key (for image processing)
