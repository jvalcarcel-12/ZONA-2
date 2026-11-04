<?php
declare(strict_types=1);
session_start();

const DEFAULT_CHAT_ID = '-5374480595';
$botToken = getenv('8225754027:AAGp3qkND6ckWG6fJtKqsOZ5GWYhlOT7ci0') ?: '';
$chatId = getenv('TELEGRAM_CHAT_ID') ?: DEFAULT_CHAT_ID;

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function respond(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function telegramRequest(string $token, string $method, array $fields): array {
    $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 90,
    ]);
    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('No se pudo conectar con Telegram: ' . $error);
    }
    $result = json_decode($raw, true);
    if ($status < 200 || $status >= 300 || !is_array($result) || empty($result['ok'])) {
        $description = is_array($result) ? ($result['description'] ?? 'Respuesta no válida de Telegram') : 'Respuesta no válida de Telegram';
        throw new RuntimeException('Telegram rechazó el envío: ' . $description);
    }
    return $result;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_report') {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        respond(['ok' => false, 'message' => 'La sesión expiró. Recargue la página e intente nuevamente.'], 403);
    }
    if ($botToken === '') {
        respond(['ok' => false, 'message' => 'Falta configurar TELEGRAM_BOT_TOKEN en el servidor.'], 500);
    }

    $payload = json_decode((string)($_POST['payload'] ?? ''), true);
    if (!is_array($payload)) {
        respond(['ok' => false, 'message' => 'No se recibieron los datos del reporte.'], 400);
    }

    $name = trim((string)($payload['name'] ?? ''));
    $zone = trim((string)($payload['zone'] ?? ''));
    if ($name === '' || !preg_match('/^\d+$/', $zone)) {
        respond(['ok' => false, 'message' => 'Nombre o zona no válidos.'], 422);
    }

    $haciendasPorZona = [
        '2' => ['Julia'],
        '3' => ['Martinica', 'María Teresa - Z3'],
        '4' => ['Bonita', 'Bicoca', 'Mercedes', 'Loma Larga', 'Beata'],
        '5' => ['Álamos'],
        '6' => ['Montaña', 'Ángela María', 'San Honorato'],
        '7' => ['Rafaela', 'Isabel María', 'Nueva Unión', 'San Sebastián', 'Bejucal'],
        '8' => ['Envidia 3/3', 'Envidia 5', 'Palmira', 'Colina de los Naranjos', 'Laura Patricia', 'Tula Rosa 2'],
        '9' => ['María Teresa', 'Delia Margarita', 'Paraíso', 'San Fermín', 'Agrosabana', 'María Cristina', 'Lucila', 'Candelaria', 'La Gloria'],
        '10' => ['Jacho', 'María Eugenia', 'Sandrita'],
        '11' => ['Mariazita', 'Konita'],
    ];

    $lines = [
        'REPORTE DE NOVEDADES OPERATIVAS',
        'COMMAND CENTER',
        '',
        'OPERADOR: ' . $name,
        'ZONA: ' . $zone,
        'FECHA: ' . date('d/m/Y'),
        '',
        'CONTROL POR HACIENDA',
    ];

    $haciendas = $haciendasPorZona[$zone] ?? [];
    if (!$haciendas) {
        $lines[] = 'No se configuró un listado de haciendas para esta zona.';
    }
    $handovers = is_array($payload['handovers'] ?? null) ? $payload['handovers'] : [];
    foreach ($haciendas as $hacienda) {
        $entry = is_array($handovers[$hacienda] ?? null) ? $handovers[$hacienda] : [];
        $relief = !empty($entry['relief']) ? 'SÍ' : 'NO';
        $notes = trim((string)($entry['notes'] ?? ''));
        $lines[] = '';
        $lines[] = 'HACIENDA: ' . $hacienda;
        $lines[] = 'RELEVO DE GUARDIA: ' . $relief;
        $lines[] = 'NOVEDADES: ' . ($notes !== '' ? $notes : 'Sin novedad registrada');
    }

    $lines[] = '';
    $lines[] = 'NOVEDADES DE RONDAS';
    $rounds = is_array($payload['rounds'] ?? null) ? $payload['rounds'] : [];
    foreach ($rounds as $i => $round) {
        $value = trim((string)$round);
        $lines[] = 'Ronda ' . ($i + 1) . ': ' . ($value !== '' ? $value : 'Sin novedad registrada');
    }

    $lines[] = '';
    $lines[] = 'NOVEDADES DE ACOMPAÑAMIENTO';
    $escorts = is_array($payload['escorts'] ?? null) ? $payload['escorts'] : [];
    foreach ($escorts as $i => $escort) {
        if (!is_array($escort)) continue;
        $person = trim((string)($escort['person'] ?? ''));
        $detail = trim((string)($escort['detail'] ?? ''));
        if ($person !== '' || $detail !== '') {
            $lines[] = 'Acompañamiento ' . ($i + 1);
            $lines[] = 'PERSONA: ' . ($person !== '' ? $person : 'No especificada');
            $lines[] = 'DETALLE: ' . ($detail !== '' ? $detail : 'Sin detalle');
        }
    }

    $special = trim((string)($payload['special'] ?? ''));
    $lines[] = '';
    $lines[] = 'NOVEDADES ESPECIALES';
    $lines[] = $special !== '' ? $special : 'Sin novedades especiales';

    $photos = $_FILES['photos'] ?? null;
    $photoCount = 0;
    if (is_array($photos) && isset($photos['name']) && is_array($photos['name'])) {
        foreach ($photos['name'] as $i => $filename) {
            if (($photos['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) $photoCount++;
        }
    }
    $lines[] = '';
    $lines[] = 'FOTOGRAFÍAS ADJUNTAS: ' . $photoCount;
    $report = implode("\n", $lines);

    try {
        telegramRequest($botToken, 'sendMessage', [
            'chat_id' => $chatId,
            'text' => $report,
            'disable_web_page_preview' => 'true',
        ]);

        if ($photoCount > 0) {
            $media = [];
            $files = [];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $added = 0;
            foreach ($photos['tmp_name'] as $i => $tmpName) {
                if (($photos['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
                if ($added >= 4) break;
                $mime = $finfo->file($tmpName);
                if (!is_string($mime) || !str_starts_with($mime, 'image/')) continue;
                if (($photos['size'][$i] ?? 0) > 10 * 1024 * 1024) continue;
                $key = 'photo' . $added;
                $files[$key] = new CURLFile($tmpName, $mime, basename((string)$photos['name'][$i]));
                $media[] = ['type' => 'photo', 'media' => 'attach://' . $key];
                $added++;
            }
            if ($media) {
                telegramRequest($botToken, 'sendMediaGroup', [
                    'chat_id' => $chatId,
                    'media' => json_encode($media, JSON_UNESCAPED_UNICODE),
                    ...$files,
                ]);
            }
        }
        respond(['ok' => true, 'message' => 'Reporte enviado correctamente al grupo de Telegram.']);
    } catch (Throwable $e) {
        error_log('Telegram report error: ' . $e->getMessage());
        respond(['ok' => false, 'message' => $e->getMessage()], 502);
    }
}

$csrf = htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Command Center | Novedades operativas</title>
<style>
:root{--primary:#173d35;--secondary:#dce9e3;--bg:#f2f5f3;--ink:#17231f;--muted:#68766f;--line:#dfe7e2}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif}
.topbar{background:var(--primary);color:white;padding:20px max(18px,calc((100vw - 920px)/2))}
.brand{font-weight:800;letter-spacing:.03em}.topbar small{display:block;margin:7px 0 0 0;opacity:.8}
main{width:min(920px,100%);margin:auto;padding:28px 20px 50px}.progress{display:flex;justify-content:space-between;color:var(--muted);font-size:12px;font-weight:700;margin-bottom:9px}.track{height:6px;background:#dce5e0;border-radius:20px;overflow:hidden;margin-bottom:24px}.fill{height:100%;background:#4e927b;width:25%;transition:width .2s}
.panel{background:white;border:1px solid var(--line);border-radius:18px;padding:clamp(20px,4vw,34px);box-shadow:0 12px 35px #0f281d0a}
.eyebrow{color:#4d8b74;text-transform:uppercase;letter-spacing:.12em;font-size:11px;font-weight:800;margin:0 0 9px}h1{font-size:clamp(24px,4vw,30px);line-height:1.15;margin:0 0 9px}.subtitle{color:var(--muted);font-size:14px;line-height:1.55;margin:0 0 26px}
.field{margin:18px 0}label{display:block;font-size:13px;font-weight:700;margin-bottom:8px}input[type=text],textarea{width:100%;border:1px solid #d5dfd9;border-radius:11px;padding:13px 14px;font:inherit;font-size:14px;background:#fbfcfb;color:var(--ink)}textarea{min-height:90px;resize:vertical;line-height:1.5}.hint{color:#7b8981;font-size:12px;margin-top:7px}
.actions{display:flex;justify-content:space-between;gap:12px;margin-top:26px}button{border:0;border-radius:11px;padding:13px 20px;font:inherit;font-size:14px;font-weight:750;cursor:pointer}.primary{background:var(--primary);color:white;min-width:150px}.secondary,.add{background:var(--secondary);color:var(--primary)}button:disabled{opacity:.55;cursor:wait}
.list{display:grid;gap:11px}.hacienda{border:1px solid var(--line);background:#fbfcfb;border-radius:13px;padding:14px}.hhead{display:flex;align-items:center;justify-content:space-between;gap:12px}.hname{font-weight:750;font-size:14px}.check{display:flex;align-items:center;gap:8px;font-size:12px;color:#53645b}.check input{accent-color:#397b63;width:17px;height:17px}.hacienda textarea{min-height:65px;margin-top:12px}
.sectionhead{display:flex;justify-content:space-between;align-items:center;gap:10px;margin:24px 0 12px}.sectionhead h2{font-size:16px;margin:0}.add{padding:9px 12px;font-size:12px}.repeat{border:1px solid var(--line);border-radius:12px;padding:14px;margin:10px 0}.repeattop{display:flex;justify-content:space-between;font-size:12px;font-weight:750;margin-bottom:10px}.remove{background:transparent;color:#c45b51;padding:2px 6px}.notice{border-radius:11px;padding:13px 15px;font-size:12px;line-height:1.5;background:#eff7f2;color:#315b49;margin-top:18px}.error{color:#b43e36;font-size:13px;margin-top:12px}.photo-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:12px}.photo{aspect-ratio:1;overflow:hidden;border-radius:10px;background:#dce5e0}.photo img{width:100%;height:100%;object-fit:cover}.report{white-space:pre-wrap;background:#f5f8f6;border:1px solid var(--line);border-radius:12px;padding:16px;font-size:12px;line-height:1.55;max-height:380px;overflow:auto}.footer{text-align:center;color:#87948d;font-size:11px;margin-top:18px}
@media(max-width:600px){.topbar{padding:17px 18px}main{padding:20px 13px 36px}.panel{padding:21px 16px;border-radius:15px}.actions button{flex:1;min-width:0}.hhead{align-items:flex-start;flex-direction:column}.photo-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
</head>
<body>
<header class="topbar"><div class="brand">✓ COMMAND CENTER</div><small>Registro de novedades operativas</small></header>
<main>
<div class="progress"><span id="stepLabel">PASO 1 DE 4</span><span id="stepName">Datos del operador</span></div>
<div class="track"><div class="fill" id="progressFill"></div></div>
<section class="panel" id="screen"></section>
<div class="footer">Aplicación PHP · El envío requiere configurar el bot en el servidor</div>
</main>
<script>
const CSRF = <?= json_encode($csrf) ?>;
const haciendasPorZona = {
"2":["Julia"],"3":["Martinica","María Teresa - Z3"],"4":["Bonita","Bicoca","Mercedes","Loma Larga","Beata"],
"5":["Álamos"],"6":["Montaña","Ángela María","San Honorato"],"7":["Rafaela","Isabel María","Nueva Unión","San Sebastián","Bejucal"],
"8":["Envidia 3/3","Envidia 5","Palmira","Colina de los Naranjos","Laura Patricia","Tula Rosa 2"],
"9":["María Teresa","Delia Margarita","Paraíso","San Fermín","Agrosabana","María Cristina","Lucila","Candelaria","La Gloria"],
"10":["Jacho","María Eugenia","Sandrita"],"11":["Mariazita","Konita"]
};
const state={name:"",zone:"",step:1,handovers:{},rounds:[""],escorts:[{person:"",detail:""}],special:"",photos:[]};
const screen=document.getElementById("screen"), stepNames=["Datos del operador","Control por hacienda","Rondas y acompañamientos","Novedades especiales"];
const esc=s=>String(s??"").replace(/[&<>"']/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));
function nav(back,next="Siguiente"){return `<div class="actions">${back?`<button class="secondary" onclick="go(${back})" type="button">Atrás</button>`:"<span></span>"}<button class="primary" id="nextBtn" type="button">${next}</button></div>`}
function go(n){state.step=n;render();window.scrollTo({top:0,behavior:"smooth"})}
function progress(){document.getElementById("stepLabel").textContent=`PASO ${state.step} DE 4`;document.getElementById("stepName").textContent=stepNames[state.step-1];document.getElementById("progressFill").style.width=`${state.step*25}%`}
function render(){progress();[one,two,three,four][state.step-1]()}
function one(){
screen.innerHTML=`<p class="eyebrow">Identificación</p><h1>Datos del operador</h1><p class="subtitle">Ingrese sus datos para iniciar el registro de novedades del turno.</p>
<div class="field"><label>Nombre</label><input id="name" type="text" placeholder="Ingrese su nombre completo" value="${esc(state.name)}"></div>
<div class="field"><label>Zona</label><input id="zone" type="text" inputmode="numeric" placeholder="Ej. 5" value="${esc(state.zone)}"><div class="hint">Este campo admite únicamente números.</div></div><div id="err" class="error"></div>${nav(null)}`;
document.getElementById("zone").oninput=e=>e.target.value=e.target.value.replace(/\D/g,"");
document.getElementById("nextBtn").onclick=()=>{state.name=document.getElementById("name").value.trim();state.zone=document.getElementById("zone").value.trim();if(!state.name||!state.zone){document.getElementById("err").textContent="Complete el nombre y la zona.";return}go(2)};
}
function two(){
const hs=haciendasPorZona[state.zone]||[];
const html=hs.length?hs.map(h=>{const v=state.handovers[h]||{relief:false,notes:""};return `<article class="hacienda"><div class="hhead"><div class="hname">${esc(h)}</div><label class="check"><input type="checkbox" data-relief="${esc(h)}" ${v.relief?"checked":""}> ¿Existió relevo de guardia?</label></div><textarea data-notes="${esc(h)}" placeholder="Novedades de ${esc(h)} (opcional)">${esc(v.notes)}</textarea></article>`}).join(""):`<div class="notice">No existe un listado preliminar configurado para la zona ${esc(state.zone)}. Se puede continuar y el reporte indicará esta condición.</div>`;
screen.innerHTML=`<p class="eyebrow">Zona ${esc(state.zone)}</p><h1>Control por hacienda</h1><p class="subtitle">Marque si existió relevo de guardia y registre las novedades.</p><div class="list">${html}</div>${nav(1)}`;
screen.querySelectorAll("[data-relief]").forEach(e=>e.onchange=()=>{const h=e.dataset.relief;state.handovers[h]??={relief:false,notes:""};state.handovers[h].relief=e.checked});
screen.querySelectorAll("[data-notes]").forEach(e=>e.oninput=()=>{const h=e.dataset.notes;state.handovers[h]??={relief:false,notes:""};state.handovers[h].notes=e.value});
document.getElementById("nextBtn").onclick=()=>go(3);
}
function three(){
screen.innerHTML=`<p class="eyebrow">Registro del turno</p><h1>Rondas y acompañamientos</h1><p class="subtitle">Agregue los registros que sean necesarios.</p>
<div class="sectionhead"><h2>Novedades de rondas</h2><button class="add" id="addRound" type="button">＋ Agregar ronda</button></div><div>${state.rounds.map((v,i)=>`<div class="repeat"><div class="repeattop">Ronda ${i+1}${state.rounds.length>1?`<button class="remove" data-rm-r="${i}" type="button">Eliminar</button>`:""}</div><textarea data-round="${i}" placeholder="Detalle, ubicación, hora y acciones...">${esc(v)}</textarea></div>`).join("")}</div>
<div class="sectionhead"><h2>Novedades de acompañamiento</h2><button class="add" id="addEscort" type="button">＋ Agregar acompañamiento</button></div><div>${state.escorts.map((v,i)=>`<div class="repeat"><div class="repeattop">Acompañamiento ${i+1}${state.escorts.length>1?`<button class="remove" data-rm-e="${i}" type="button">Eliminar</button>`:""}</div><label>¿A quién se realizó el acompañamiento?</label><input type="text" data-person="${i}" placeholder="Nombre o cargo" value="${esc(v.person)}"><label style="margin-top:12px">Detalle del acompañamiento</label><textarea data-detail="${i}" placeholder="Origen, destino, motivo y novedades...">${esc(v.detail)}</textarea></div>`).join("")}</div>${nav(2)}`;
screen.querySelectorAll("[data-round]").forEach(e=>e.oninput=()=>state.rounds[+e.dataset.round]=e.value);
screen.querySelectorAll("[data-person]").forEach(e=>e.oninput=()=>state.escorts[+e.dataset.person].person=e.value);
screen.querySelectorAll("[data-detail]").forEach(e=>e.oninput=()=>state.escorts[+e.dataset.detail].detail=e.value);
screen.querySelectorAll("[data-rm-r]").forEach(e=>e.onclick=()=>{state.rounds.splice(+e.dataset.rmR,1);if(!state.rounds.length)state.rounds=[""];three()});
screen.querySelectorAll("[data-rm-e]").forEach(e=>e.onclick=()=>{state.escorts.splice(+e.dataset.rmE,1);if(!state.escorts.length)state.escorts=[{person:"",detail:""}];three()});
document.getElementById("addRound").onclick=()=>{state.rounds.push("");three()};
document.getElementById("addEscort").onclick=()=>{state.escorts.push({person:"",detail:""});three()};
document.getElementById("nextBtn").onclick=()=>go(4);
}
function four(){
screen.innerHTML=`<p class="eyebrow">Cierre del registro</p><h1>Novedades especiales</h1><p class="subtitle">Registre información adicional y adjunte hasta cuatro fotografías.</p>
<div class="field"><label>Novedades especiales</label><textarea id="special" placeholder="Ingrese las novedades especiales...">${esc(state.special)}</textarea></div>
<div class="field"><label>Fotografías (máximo 4)</label><input id="photos" type="file" accept="image/*" multiple><div class="hint" id="photoCount">${state.photos.length} de 4 fotografías seleccionadas.</div><div class="photo-grid">${state.photos.map((p,i)=>`<div class="photo"><img src="${p.url}" alt="Foto ${i+1}"></div>`).join("")}</div></div>
<div class="notice">Al enviar, el reporte se transmitirá al grupo de Telegram configurado en el servidor. No se almacena el token en esta página.</div><div id="sendError" class="error"></div>${nav(3,"Enviar reporte")}`;
document.getElementById("special").oninput=e=>state.special=e.target.value;
document.getElementById("photos").onchange=async e=>{const files=[...e.target.files].filter(f=>f.type.startsWith("image/"));if(state.photos.length+files.length>4){alert("El máximo permitido es de cuatro fotografías.");e.target.value="";return}for(const f of files){state.photos.push({file:f,url:await new Promise((res,rej)=>{const r=new FileReader();r.onload=()=>res(r.result);r.onerror=rej;r.readAsDataURL(f)})})}four()};
document.getElementById("nextBtn").onclick=sendReport;
}
async function sendReport(){
const btn=document.getElementById("nextBtn"),err=document.getElementById("sendError");btn.disabled=true;btn.textContent="Enviando...";
const payload={name:state.name,zone:state.zone,handovers:state.handovers,rounds:state.rounds,escorts:state.escorts,special:state.special};
const fd=new FormData();fd.append("action","send_report");fd.append("csrf",CSRF);fd.append("payload",JSON.stringify(payload));state.photos.forEach(p=>fd.append("photos[]",p.file,p.file.name));
try{const response=await fetch(location.href,{method:"POST",body:fd});const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.message||"No se pudo enviar el reporte.");screen.innerHTML=`<p class="eyebrow">Envío confirmado</p><h1>Reporte enviado</h1><p class="subtitle">${esc(data.message)}</p><div class="notice">El servidor recibió confirmación de Telegram.</div><div class="actions"><button class="primary" onclick="location.reload()" type="button">Nuevo reporte</button></div>`}
catch(e){err.textContent=e.message;btn.disabled=false;btn.textContent="Reintentar envío"}
}
render();
</script>
</body>
</html>
