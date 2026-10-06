<?php
// دالة لجلب Access Token صالح دوماً (تجدده تلقائياً إذا انتهى)
function getYouTubeAccessToken($email, $con, $client_id, $client_secret) {
    $stmt = $con->prepare("SELECT * FROM user_tokens WHERE user_email = ? AND platform = 'youtube'");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $token_data = $stmt->get_result()->fetch_assoc();

    if (!$token_data) return false;

    // التحقق إذا كان التوكن قد انتهى أو قارب على الانتهاء (أقل من 5 دقائق)
    if (strtotime($token_data['expires_at']) <= time() + 300) {
        $ch = curl_init("https://oauth2.googleapis.com/token");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
            'refresh_token' => $token_data['refresh_token'],
            'grant_type'    => 'refresh_token'
        ]));
        
        $response = json_decode(curl_exec($ch), true);
        
        if (isset($response['access_token'])) {
            $new_token = $response['access_token'];
            $new_expiry = date("Y-m-d H:i:s", time() + $response['expires_in']);

            $update = $con->prepare("UPDATE user_tokens SET access_token = ?, expires_at = ? WHERE user_email = ?");
            $update->bind_param("sss", $new_token, $new_expiry, $email);
            $update->execute();

            return $new_token;
        }
    }

    return $token_data['access_token'];
}