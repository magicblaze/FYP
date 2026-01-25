<?php
require_once __DIR__ . '/config.php';
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Terms of Use - HappyDesign</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css">
    <style>
        .terms-container { max-width: 900px; margin: 2.5rem auto; }
        .terms-section + .terms-section { margin-top: 1.25rem; }
        .muted { color: #6c757d; }
    </style>
</head>
<body>
    <main class="terms-container container">
        <h1>HappyDesign - Terms of Use</h1>
        <p class="text-muted">Effective date: <?= date('Y-m-d') ?></p>

        <div class="terms-section">
            <h4>1. Introduction</h4>
            <p>These Terms of Use ("Terms") govern your access to and use of the HappyDesign website and services (the "Service"). By placing an order through HappyDesign, you ("Client") agree to these Terms. If you do not agree, do not place an order.</p>
        </div>

        <div class="terms-section">
            <h4>2. Services</h4>
            <p>HappyDesign connects Clients with Designers to provide custom design services. When you place an order you engage the selected Designer to perform services as described in your order. The Designer is an independent service provider and not an employee of HappyDesign unless explicitly stated.</p>
        </div>

        <div class="terms-section">
            <h4>3. User Obligations</h4>
            <ul>
                <li>You must provide accurate and complete information, including any floor plans, measurements, and other materials necessary for the Designer to perform the work.</li>
                <li>You confirm that you have the necessary rights and permissions for any materials you upload.</li>
                <li>You agree to provide timely responses to Designer requests and to review submissions promptly.</li>
            </ul>
        </div>

        <div class="terms-section">
            <h4>4. Pricing, Payment and Fees</h4>
            <p>Order fees and pricing are shown at checkout. Payment methods available on the platform may include third-party providers (e.g., PayPal, AlipayHK, FPS). All payments are subject to the terms of the payment provider. Fees paid are for the Designer's services and platform facilitation; additional taxes or transaction fees may apply.</p>
        </div>

        <div class="terms-section">
            <h4>5. Cancellations and Refunds</h4>
            <p>Cancellations, refunds, and revisions are governed by the specific cancellation policy visible at the time of ordering and by any Designer-specific terms. If you request a cancellation, contact support or the Designer promptly. Refunds may be issued at the discretion of HappyDesign and/or the Designer based on work performed.</p>
        </div>

        <div class="terms-section">
            <h4>6. Intellectual Property</h4>
            <p>Design deliverables, drafts and final works remain the intellectual property of their respective creators until transferred in writing. Unless otherwise agreed, Designers grant Clients a license to use final deliverables for the intended purpose described in the order.</p>
        </div>

        <div class="terms-section">
            <h4>7. Delivery and Revisions</h4>
            <p>Delivery timelines and allowed revisions are stated in the order or communicated by the Designer. Clients are responsible for reviewing submissions and requesting revisions within the timeframe specified. Unused revision requests may be forfeited if not claimed within the stated period.</p>
        </div>

        <div class="terms-section">
            <h4>8. Warranties and Disclaimers</h4>
            <p>To the fullest extent permitted by law, HappyDesign provides the platform "as is" and disclaims all warranties, express or implied, including merchantability and fitness for a particular purpose. HappyDesign does not guarantee Designer performance or outcomes.</p>
        </div>

        <div class="terms-section">
            <h4>9. Limitation of Liability</h4>
            <p>HappyDesign, its officers and agents will not be liable for indirect, incidental, special, consequential or punitive damages arising from use of the Service. Maximum aggregate liability to you for any claim related to the Service will not exceed the total fees you paid for the specific order giving rise to the claim.</p>
        </div>

        <div class="terms-section">
            <h4>10. Privacy</h4>
            <p>We collect and use personal information in accordance with our Privacy Policy. Please review the Privacy Policy before placing orders.</p>
        </div>

        <div class="terms-section">
            <h4>11. Governing Law</h4>
            <p>These Terms are governed by the laws of the jurisdiction where HappyDesign operates. Any disputes shall be resolved in the competent courts of that jurisdiction unless otherwise agreed in writing.</p>
        </div>

        <div class="terms-section">
            <h4>12. Changes to Terms</h4>
            <p>We may update these Terms from time to time. Continued use of the Service after changes constitutes acceptance of the revised Terms.</p>
        </div>

        <div class="terms-section">
            <h4>13. Contact</h4>
            <p>If you have questions about these Terms, contact us at support@happydesign.example or via the contact page on the site.</p>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
