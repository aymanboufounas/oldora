<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Policy - Oldora</title>
    <style>
        body { font-family: sans-serif; line-height: 1.6; color: #333; max-width: 800px; margin: 0 auto; padding: 20px; }
        h1 { color: #2c3e50; }
        h2 { color: #34495e; margin-top: 30px; }
        p { margin-bottom: 15px; }
        ul { margin-bottom: 15px; }
        .container { background: #fff; padding: 40px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .footer { margin-top: 50px; font-size: 0.9em; color: #777; text-align: center; }
    </style>
</head>
<body>

<div class="container">
    <h1>Privacy Policy</h1>
    <p><strong>Effective Date:</strong> <?php echo date("Y-m-d"); ?></p>

    <p>Welcome to <strong>Oldora</strong> ("we," "our," or "us"). We are committed to protecting your privacy and ensuring you understand how we handle your data when you use our services, including our integration with YouTube and TikTok.</p>

    <h2>1. Information We Collect</h2>
    <p>We collect the following types of information to provide our services:</p>
    <ul>
        <li><strong>Account Information:</strong> When you register, we collect your email address and password (encrypted) to create your account.</li>
        <li><strong>Platform Data (TikTok & YouTube):</strong> When you choose to connect your social media accounts, we access specific information via their APIs:
            <ul>
                <li><strong>TikTok:</strong> We access your display name, avatar image, and basic profile information (via <code>user.info.basic</code> scope) and permission to upload videos (via <code>video.upload</code> scope).</li>
                <li><strong>YouTube:</strong> We access your channel statistics and basic account info.</li>
            </ul>
        </li>
    </ul>

    <h2>2. How We Use Your Information</h2>
    <p>We use the collected information solely for the following purposes:</p>
    <ul>
        <li>To display your profile name and avatar on your Oldora dashboard.</li>
        <li>To provide analytics and insights about your content performance.</li>
        <li>To enable you to upload and schedule content directly from Oldora to your connected platforms (only upon your explicit action).</li>
        <li>We <strong>do not</strong> sell or share your personal data with third parties for advertising purposes.</li>
    </ul>

    <h2>3. Third-Party Services</h2>
    <p>Our service uses API services from third parties. By using Oldora, you also agree to the privacy policies of these platforms:</p>
    <ul>
        <li><strong>TikTok:</strong> We use TikTok API Services. Please refer to the <a href="https://www.tiktok.com/legal/privacy-policy" target="_blank">TikTok Privacy Policy</a>.</li>
        <li><strong>YouTube:</strong> We use YouTube API Services. Please refer to the <a href="https://policies.google.com/privacy" target="_blank">Google Privacy Policy</a>.</li>
    </ul>

    <h2>4. Data Retention and Deletion</h2>
    <p>We store your access tokens securely in our database. These tokens allow us to connect to the platforms on your behalf.</p>
    <p><strong>How to delete your data:</strong> You can disconnect your accounts or delete your Oldora account at any time from your dashboard settings. Upon deletion, all your tokens and personal data will be permanently removed from our servers.</p>
    <p>You can also revoke access directly through the platforms:</p>
    <ul>
        <li><a href="https://security.google.com/settings/security/permissions" target="_blank">Google Security Settings</a> (for YouTube)</li>
        <li>TikTok App Settings > Security & Login > Manage App Permissions</li>
    </ul>

    <h2>5. Contact Us</h2>
    <p>If you have any questions about this Privacy Policy, please contact us at:</p>
    <p>Email: <strong>support@oldora.vip</strong></p>
</div>

<div class="footer">
    &copy; <?php echo date("Y"); ?> Oldora. All rights reserved.
</div>

</body>
</html>