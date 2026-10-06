<?php
http_response_code(404);

// منع الكاش
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>404 | OLDORA</title>

  <link rel="icon" type="image/png" href="https://i.postimg.cc/bv1QQwBc/1768055586557.png">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

  <style>
    :root {
      --primary-gradient: linear-gradient(135deg, #00C6FF 0%, #0072FF 100%);
      --glass-bg: rgba(255, 255, 255, 0.05);
      --glass-border: 1px solid rgba(255, 255, 255, 0.12);
      --text-color: #ffffff;
      --muted: rgba(255,255,255,0.6);
      --input-bg: rgba(0, 0, 0, 0.30);
      --neon: #00fff0;
    }

    * { box-sizing: border-box; }

    body {
      margin: 0;
      min-height: 100vh;
      background-color: #09090b;
      background-image: radial-gradient(circle at 50% 0%, #1a1a2e 0%, #000000 100%);
      font-family: 'Poppins', sans-serif;
      color: var(--text-color);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
      overflow: hidden;
    }

    /* خلفية تأثيرات */
    .bg-orb {
      position: absolute;
      width: 420px;
      height: 420px;
      border-radius: 50%;
      filter: blur(70px);
      opacity: 0.35;
      z-index: 0;
      pointer-events: none;
    }
    .orb-1 { top: -140px; left: -120px; background: rgba(0, 198, 255, 0.8); }
    .orb-2 { bottom: -160px; right: -140px; background: rgba(0, 255, 240, 0.7); }
    .orb-3 { top: 50%; left: 50%; transform: translate(-50%,-50%); width: 260px; height: 260px; background: rgba(114, 46, 255, 0.6); opacity: 0.18; }

    .container-box {
      position: relative;
      z-index: 2;
      width: 100%;
      max-width: 560px;
      background: var(--glass-bg);
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
      border: var(--glass-border);
      border-radius: 26px;
      padding: 42px 38px;
      box-shadow: 0 20px 60px rgba(0,0,0,0.55);
      text-align: center;
      overflow: hidden;
    }

    /* Glow top */
    .container-box::before {
      content: '';
      position: absolute;
      top: -60px;
      left: 50%;
      transform: translateX(-50%);
      width: 170px;
      height: 170px;
      background: rgba(0, 198, 255, 0.30);
      filter: blur(65px);
      z-index: -1;
    }

    .logo-img {
      width: 110px;
      margin: 0 auto 14px auto;
      display: block;
    }

    .brand-name {
      font-size: 1.55rem;
      font-weight: 800;
      letter-spacing: 2px;
      margin: 0 0 10px 0;
      background: var(--primary-gradient);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }

    .badge {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: rgba(0, 255, 240, 0.08);
      border: 1px solid rgba(0, 255, 240, 0.18);
      padding: 8px 14px;
      border-radius: 999px;
      color: var(--neon);
      font-weight: 600;
      font-size: 0.85rem;
      margin-bottom: 18px;
    }

    .code {
      font-size: 68px;
      line-height: 1;
      margin: 10px 0 12px 0;
      font-weight: 800;
      letter-spacing: 2px;
      color: #fff;
      text-shadow: 0 0 30px rgba(0, 255, 240, 0.12);
    }

    .title {
      font-size: 1.1rem;
      font-weight: 700;
      margin: 0 0 10px 0;
    }

    .subtitle {
      margin: 0 auto 22px auto;
      max-width: 430px;
      color: var(--muted);
      font-size: 0.95rem;
      line-height: 1.75;
    }

    .actions {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
      margin-top: 18px;
    }
    @media (max-width: 520px) {
      .actions { grid-template-columns: 1fr; }
      .container-box { padding: 34px 20px; }
      .code { font-size: 56px; }
    }

    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      width: 100%;
      padding: 14px 16px;
      border-radius: 14px;
      border: none;
      cursor: pointer;
      text-decoration: none;
      font-weight: 700;
      transition: 0.25s;
      user-select: none;
      white-space: nowrap;
    }

    .btn-primary {
      background: var(--primary-gradient);
      color: #fff;
      box-shadow: 0 10px 22px rgba(0, 198, 255, 0.22);
    }
    .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 14px 26px rgba(0, 198, 255, 0.28); }

    .btn-ghost {
      background: rgba(255,255,255,0.06);
      border: 1px solid rgba(255,255,255,0.10);
      color: #fff;
    }
    .btn-ghost:hover { transform: translateY(-2px); border-color: rgba(0,255,240,0.28); }

    .help {
      margin-top: 18px;
      font-size: 0.85rem;
      color: rgba(255,255,255,0.55);
      line-height: 1.7;
    }

    .help a {
      color: #fff;
      text-decoration: none;
      border-bottom: 1px dashed rgba(255,255,255,0.35);
    }
    .help a:hover { color: var(--neon); border-bottom-color: rgba(0,255,240,0.45); }

    .footer-mini {
      margin-top: 18px;
      font-size: 0.75rem;
      color: rgba(255,255,255,0.35);
    }
  </style>
</head>
<body>

  <div class="bg-orb orb-1"></div>
  <div class="bg-orb orb-2"></div>
  <div class="bg-orb orb-3"></div>

  <div class="container-box">
    <img src="https://i.postimg.cc/bv1QQwBc/1768055586557.png" class="logo-img" alt="OLDORA Logo" />
    <div class="brand-name">OLDORA</div>

    <div class="badge">
      <i class="fa-solid fa-circle-exclamation"></i>
      Page Not Found
    </div>

    <div class="code">404</div>

    <h1 class="title">Oops! We can’t find that page.</h1>
    <p class="subtitle">
      The page you’re looking for might have been moved, deleted, or the URL may be incorrect.
      Use the buttons below to get back on track.
    </p>

    <div class="actions">
      <a class="btn btn-primary" href="/">
        <i class="fa-solid fa-house"></i>
        Back to Home
      </a>
      <a class="btn btn-ghost" href="/login-user.php">
        <i class="fa-solid fa-right-to-bracket"></i>
        Login
      </a>
    </div>

    <div class="help">
      If you believe this is an error, please contact us at
      <a href="mailto:support@oldora.vip">support@oldora.vip</a>
      (or update this email to your real support address).
    </div>

    <div class="footer-mini">
      © <?php echo date('Y'); ?> OLDORA. All rights reserved.
    </div>
  </div>

</body>
</html>
