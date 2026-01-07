<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PERTI API Documentation</title>
    <link rel="icon" type="image/png" href="assets/img/favicon.png">
    <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5.11.0/swagger-ui.css">
    <style>
        /* PERTI Theme Customization */
        :root {
            --perti-primary: #239BCD;
            --perti-primary-dark: #332e7a;
            --perti-bg-dark: #242444;
            --perti-bg-darker: #1a1a2e;
            --perti-success: #63BD49;
            --perti-danger: #F04124;
        }

        body {
            margin: 0;
            padding: 0;
            background: var(--perti-bg-darker);
        }

        /* Header bar */
        .swagger-header {
            background: linear-gradient(135deg, var(--perti-primary-dark) 0%, var(--perti-bg-dark) 100%);
            padding: 15px 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
        }

        .swagger-header .logo {
            display: flex;
            align-items: center;
            gap: 15px;
            text-decoration: none;
        }

        .swagger-header .logo img {
            height: 40px;
        }

        .swagger-header .logo span {
            color: #fff;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 18px;
            font-weight: 600;
        }

        .swagger-header .back-link {
            color: var(--perti-primary);
            text-decoration: none;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 14px;
            padding: 8px 16px;
            border: 1px solid var(--perti-primary);
            border-radius: 4px;
            transition: all 0.2s ease;
        }

        .swagger-header .back-link:hover {
            background: var(--perti-primary);
            color: #fff;
        }

        /* Swagger UI Dark Theme Overrides */
        .swagger-ui {
            background: var(--perti-bg-darker);
        }

        .swagger-ui .topbar {
            display: none;
        }

        .swagger-ui .info {
            margin: 30px 0;
        }

        .swagger-ui .info .title {
            color: #fff;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .swagger-ui .info .description p,
        .swagger-ui .info .description {
            color: #ccc;
        }

        .swagger-ui .scheme-container {
            background: var(--perti-bg-dark);
            box-shadow: none;
            padding: 20px;
        }

        .swagger-ui .opblock-tag {
            color: #fff;
            border-bottom: 1px solid #444;
        }

        .swagger-ui .opblock-tag:hover {
            background: rgba(255,255,255,0.05);
        }

        .swagger-ui .opblock {
            background: rgba(255,255,255,0.02);
            border-color: #444;
            box-shadow: none;
        }

        .swagger-ui .opblock .opblock-summary {
            border-color: #444;
        }

        .swagger-ui .opblock .opblock-summary-method {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-weight: 600;
        }

        .swagger-ui .opblock .opblock-summary-path {
            color: #fff;
        }

        .swagger-ui .opblock .opblock-summary-description {
            color: #aaa;
        }

        .swagger-ui .opblock.opblock-get {
            background: rgba(97, 175, 254, 0.1);
            border-color: #61affe;
        }

        .swagger-ui .opblock.opblock-get .opblock-summary {
            border-color: #61affe;
        }

        .swagger-ui .opblock.opblock-post {
            background: rgba(73, 204, 144, 0.1);
            border-color: #49cc90;
        }

        .swagger-ui .opblock.opblock-post .opblock-summary {
            border-color: #49cc90;
        }

        .swagger-ui .opblock.opblock-delete {
            background: rgba(249, 62, 62, 0.1);
            border-color: #f93e3e;
        }

        .swagger-ui .opblock.opblock-delete .opblock-summary {
            border-color: #f93e3e;
        }

        .swagger-ui .opblock.opblock-put {
            background: rgba(252, 161, 48, 0.1);
            border-color: #fca130;
        }

        .swagger-ui .opblock.opblock-put .opblock-summary {
            border-color: #fca130;
        }

        .swagger-ui .opblock-body {
            background: var(--perti-bg-dark);
        }

        .swagger-ui .opblock-description-wrapper p,
        .swagger-ui .opblock-external-docs-wrapper p,
        .swagger-ui .opblock-title_normal p {
            color: #ccc;
        }

        .swagger-ui .tab li {
            color: #fff;
        }

        .swagger-ui .response-col_status {
            color: #fff;
        }

        .swagger-ui .response-col_description {
            color: #ccc;
        }

        .swagger-ui table thead tr td,
        .swagger-ui table thead tr th {
            color: #fff;
            border-color: #444;
        }

        .swagger-ui .parameter__name,
        .swagger-ui .parameter__type {
            color: #fff;
        }

        .swagger-ui .parameter__in {
            color: #888;
        }

        .swagger-ui .model-title {
            color: #fff;
        }

        .swagger-ui .model {
            color: #ccc;
        }

        .swagger-ui .prop-type {
            color: var(--perti-primary);
        }

        .swagger-ui .model-box {
            background: rgba(0,0,0,0.2);
        }

        .swagger-ui select {
            background: var(--perti-bg-dark);
            color: #fff;
            border-color: #444;
        }

        .swagger-ui input[type=text],
        .swagger-ui textarea {
            background: var(--perti-bg-dark);
            color: #fff;
            border-color: #444;
        }

        .swagger-ui .btn {
            border-radius: 4px;
        }

        .swagger-ui .btn.execute {
            background: var(--perti-primary);
            border-color: var(--perti-primary);
        }

        .swagger-ui .btn.execute:hover {
            background: #1e88c7;
        }

        .swagger-ui .btn.cancel {
            background: var(--perti-danger);
            border-color: var(--perti-danger);
        }

        .swagger-ui .responses-inner {
            background: rgba(0,0,0,0.2);
        }

        .swagger-ui .highlight-code {
            background: #1e1e3f;
        }

        .swagger-ui .microlight {
            background: #1e1e3f;
            color: #ccc;
        }

        .swagger-ui .markdown code,
        .swagger-ui .renderedMarkdown code {
            background: rgba(0,0,0,0.3);
            color: var(--perti-primary);
        }

        /* Filter/Search input */
        .swagger-ui .filter-container {
            background: var(--perti-bg-dark);
            padding: 15px;
            margin-bottom: 20px;
        }

        .swagger-ui .filter-container input {
            background: var(--perti-bg-darker);
            border: 1px solid #444;
            color: #fff;
        }

        /* Wrapper styling */
        .swagger-ui .wrapper {
            max-width: 1460px;
            padding: 0 20px;
        }

        /* Loading indicator */
        .swagger-ui .loading-container {
            background: var(--perti-bg-darker);
        }

        /* Authorization button */
        .swagger-ui .auth-wrapper {
            background: var(--perti-bg-dark);
        }

        .swagger-ui .authorization__btn {
            border-color: var(--perti-primary);
        }

        /* Models section */
        .swagger-ui section.models {
            background: var(--perti-bg-dark);
            border-color: #444;
        }

        .swagger-ui section.models h4 {
            color: #fff;
        }

        .swagger-ui section.models .model-container {
            background: rgba(0,0,0,0.2);
            border-color: #444;
        }

        /* Version badge */
        .swagger-ui .info .version-stamp {
            background: var(--perti-primary);
        }

        /* Scrollbar styling */
        .swagger-ui ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        .swagger-ui ::-webkit-scrollbar-track {
            background: var(--perti-bg-darker);
        }

        .swagger-ui ::-webkit-scrollbar-thumb {
            background: #444;
            border-radius: 4px;
        }

        .swagger-ui ::-webkit-scrollbar-thumb:hover {
            background: #555;
        }
    </style>
</head>
<body>
    <div class="swagger-header">
        <a href="./" class="logo">
            <img src="assets/img/logo.png" alt="PERTI Logo">
            <span>API Documentation</span>
        </a>
        <a href="./" class="back-link">
            &larr; Back to PERTI
        </a>
    </div>

    <div id="swagger-ui"></div>

    <script src="https://unpkg.com/swagger-ui-dist@5.11.0/swagger-ui-bundle.js"></script>
    <script src="https://unpkg.com/swagger-ui-dist@5.11.0/swagger-ui-standalone-preset.js"></script>
    <script>
        window.onload = function() {
            const ui = SwaggerUIBundle({
                url: "api-docs/openapi.yaml",
                dom_id: '#swagger-ui',
                deepLinking: true,
                presets: [
                    SwaggerUIBundle.presets.apis,
                    SwaggerUIStandalonePreset
                ],
                plugins: [
                    SwaggerUIBundle.plugins.DownloadUrl
                ],
                layout: "StandaloneLayout",
                docExpansion: "list",
                filter: true,
                tagsSorter: "alpha",
                operationsSorter: "alpha",
                defaultModelsExpandDepth: 1,
                defaultModelExpandDepth: 2,
                displayRequestDuration: true,
                tryItOutEnabled: true,
                persistAuthorization: true,
                withCredentials: true
            });

            window.ui = ui;
        };
    </script>
</body>
</html>
