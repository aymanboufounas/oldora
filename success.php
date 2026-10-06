<?php
require_once __DIR__ . '/connection.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['email'])) {
    header('Location: login-user.php');
    exit;
}
$orderId = trim((string) ($_GET['order_id'] ?? ($_SESSION['last_payment_order'] ?? '')));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Payment status | OLDORA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;color:#f7f9ff;background:radial-gradient(circle at 15% 15%,rgba(66,232,224,.18),transparent 30%),radial-gradient(circle at 85% 20%,rgba(166,108,255,.16),transparent 32%),#050914;font-family:Inter,sans-serif}.card{width:min(100%,520px);padding:38px;border:1px solid rgba(255,255,255,.1);border-radius:28px;background:rgba(10,16,31,.84);box-shadow:0 30px 80px rgba(0,0,0,.35);text-align:center;backdrop-filter:blur(24px)}.icon{width:82px;height:82px;margin:0 auto 24px;display:grid;place-items:center;border-radius:25px;background:rgba(66,232,224,.1);color:#42e8e0;font-size:34px}.spinner{width:34px;height:34px;border:3px solid rgba(66,232,224,.18);border-top-color:#42e8e0;border-radius:50%;animation:spin .8s linear infinite}@keyframes spin{to{transform:rotate(360deg)}}h1{margin:0 0 10px;font-size:30px}p{margin:0 auto;color:#9ba6bb;line-height:1.7}.order{margin:24px 0;padding:13px 16px;border-radius:13px;background:rgba(255,255,255,.045);color:#b6c1d4;font-size:12px;word-break:break-all}.actions{display:flex;gap:10px;margin-top:24px}.btn{flex:1;padding:13px 18px;border-radius:13px;text-decoration:none;font-weight:700}.primary{color:#061017;background:#42e8e0}.secondary{color:#fff;border:1px solid rgba(255,255,255,.13)}.success .icon{background:rgba(58,231,158,.12);color:#3ae79e}.failed .icon{background:rgba(255,99,132,.12);color:#ff6384}@media(max-width:520px){.card{padding:28px 20px}.actions{flex-direction:column}}
    </style>
</head>
<body>
<main class="card" id="statusCard">
    <div class="icon" id="statusIcon"><span class="spinner"></span></div>
    <h1 id="statusTitle">Confirming payment</h1>
    <p id="statusMessage">We are checking the blockchain and will add your credits automatically.</p>
    <div class="order">Order: <?php echo htmlspecialchars($orderId); ?></div>
    <div class="actions"><a class="btn primary" href="planing.php">View credits</a><a class="btn secondary" href="support.php">Need help?</a></div>
</main>
<script>
(function(){
    var order=<?php echo json_encode($orderId); ?>, attempts=0;
    var card=document.getElementById('statusCard'),icon=document.getElementById('statusIcon'),title=document.getElementById('statusTitle'),message=document.getElementById('statusMessage');
    function render(data){
        if(data.status==='paid'){
            card.className='card success'; icon.innerHTML='✓'; title.textContent='Payment confirmed'; message.textContent=data.credits+' credits were added to your balance.'; return true;
        }
        if(data.status==='failed'||data.status==='create_failed'){
            card.className='card failed'; icon.innerHTML='!'; title.textContent='Payment not completed'; message.textContent=data.message||'Please retry or contact support.'; return true;
        }
        message.textContent=data.message||'Waiting for blockchain confirmation.'; return false;
    }
    function check(){
        if(!order){card.className='card failed';icon.innerHTML='!';title.textContent='Order not found';message.textContent='Return to the plans page and try again.';return;}
        fetch('payment-status.php?order_id='+encodeURIComponent(order),{credentials:'same-origin',cache:'no-store'})
            .then(function(r){return r.json()}).then(function(data){if(render(data))return;attempts++;if(attempts<40)setTimeout(check,5000)})
            .catch(function(){attempts++;if(attempts<40)setTimeout(check,7000)});
    }
    check();
})();
</script>
</body>
</html>

