<?php
http_response_code(429);
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>429 | OLDORA</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="icon" type="image/png" href="https://i.postimg.cc/bv1QQwBc/1768055586557.png">
  <style>
    :root{
      --primary-gradient: linear-gradient(135deg, #00C6FF 0%, #0072FF 100%);
      --glass-bg: rgba(255, 255, 255, 0.05);
      --glass-border: 1px solid rgba(255, 255, 255, 0.1);
      --text-color: #ffffff;
      --muted: #9aa0a6;
      --danger: #ff4757;
      --input-bg: rgba(0, 0, 0, 0.3);
    }
    body{
      margin:0; min-height:100vh;
      background-color:#09090b;
      background-image: radial-gradient(circle at 50% 0%, #1a1a2e 0%, #000000 100%);
      font-family:'Poppins',sans-serif;
      display:flex; align-items:center; justify-content:center;
      color:var(--text-color);
      padding:20px;
    }
    .container-box{
      background:var(--glass-bg);
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
      border:var(--glass-border);
      border-radius:24px;
      padding:40px;
      width:100%;
      max-width:520px;
      text-align:center;
      box-shadow:0 20px 50px rgba(0,0,0,0.5);
      position:relative;
      overflow:hidden;
    }
    .container-box::before{
      content:'';
      position:absolute;
      top:-50px;
      left:50%;
      transform:translateX(-50%);
      width:150px; height:150px;
      background:rgba(0,198,255,0.3);
      filter:blur(60px);
      z-index:-1;
    }
    .logo-img{width:120px;margin:0 auto 12px auto;display:block;}
    .brand-name{
      font-size:1.5rem;
      font-weight:700;
      margin-bottom:5px;
      letter-spacing:2px;
      background:var(--primary-gradient);
      -webkit-background-clip:text;
      -webkit-text-fill-color:transparent;
    }
    .code{
      font-size:3rem;
      font-weight:800;
      letter-spacing:2px;
      margin:12px 0 6px;
    }
    .title{font-size:1.1rem;font-weight:700;margin:0 0 8px;}
    .subtitle{font-size:0.95rem;color:var(--muted);margin:0 0 20px;line-height:1.7;}
    .hint{
      text-align:left;
      background:rgba(0,0,0,0.25);
      border:1px solid rgba(255,255,255,0.08);
      border-radius:14px;
      padding:14px 14px;
      margin:18px 0 22px;
      color:#e8eaed;
      font-size:0.92rem;
      line-height:1.7;
    }
    .hint strong{color:#fff;}
    .actions{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-top:10px;}
    .btn{
      display:inline-flex; align-items:center; gap:8px;
      padding:12px 16px;
      border-radius:12px;
      text-decoration:none;
      font-weight:600;
      transition:0.2s;
      border:1px solid rgba(255,255,255,0.12);
      background:rgba(255,255,255,0.06);
      color:#fff;
    }
    .btn-primary{
      background:var(--primary-gradient);
      border:none;
      box-shadow:0 6px 18px rgba(0, 198, 255, 0.25);
    }
    .btn:hover{transform:translateY(-2px);}
    .footer{margin-top:18px;color:#6b7280;font-size:12px;line-height:1.6;}
    .pill{
      display:inline-flex;align-items:center;gap:8px;
      padding:8px 12px;
      border-radius:999px;
      background:rgba(255,71,87,0.10);
      border:1px solid rgba(255,71,87,0.25);
      color:var(--danger);
      font-weight:700;
      font-size:12px;
      margin-top:6px;
    }
  </style>
</head>
<body>
  <div class="container-box">
    <img src="https://i.postimg.cc/bv1QQwBc/1768055586557.png" alt="OLDORA Logo" class="logo-img">
    <div class="brand-name">OLDORA</div>

    <div class="code">429</div>
    <div class="title">Too Many Requests</div>
    <div class="subtitle">You have sent too many requests in a short time.</div>

    <div class="pill"><i class="fa-solid fa-triangle-exclamation"></i> Error 429</div>

    <div class="hint">
      <strong>What you can do:</strong><br>
      • Wait a bit and try again.<br>• If you are running automation, reduce the frequency.
    </div>

    <div class="actions">
      <a class="btn btn-primary" href="/home.php"><i class="fa-solid fa-house"></i> Go to Home</a>
      <a class="btn" href="/login-user.php"><i class="fa-solid fa-right-to-bracket"></i> Login</a>
      <a class="btn" href="/connect-platforms.php"><i class="fa-solid fa-link"></i> Connected Accounts</a>
    </div>

    <div class="footer">
      If the problem continues, contact support from your dashboard.<br>
      © <?php echo date('Y'); ?> OLDORA.
    </div>
  </div>
</body>
</html>
