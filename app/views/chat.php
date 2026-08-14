<?php /** @var array $cfg */ ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($cfg['widget_title']) ?> — ezbot-local</title>
<style>
	:root { --bg:#f4f6f8; --card:#fff; --brand:#2b7de9; --brand-d:#1c5fc0; --user:#daf1e0; --ink:#1e2a33; --muted:#7a8894; --line:#e5e9ee; }
	* { box-sizing:border-box; }
	body { margin:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Hiragino Sans","Noto Sans JP",sans-serif; background:var(--bg); color:var(--ink); }
	.wrap { max-width:720px; margin:0 auto; height:100dvh; display:flex; flex-direction:column; }
	header { padding:16px 20px; background:var(--brand); color:#fff; display:flex; align-items:center; gap:12px; }
	header .ico { width:40px; height:40px; border-radius:50%; background:#fff2; display:grid; place-items:center; font-size:22px; }
	header h1 { font-size:16px; margin:0; }
	header .sub { font-size:12px; opacity:.85; margin-top:2px; }
	header a.admin { margin-left:auto; color:#fff; font-size:12px; text-decoration:none; border:1px solid #fff6; padding:5px 10px; border-radius:6px; }
	#log { flex:1; overflow-y:auto; padding:20px; display:flex; flex-direction:column; gap:12px; }
	.msg { max-width:80%; padding:11px 14px; border-radius:14px; line-height:1.6; white-space:pre-wrap; word-break:break-word; font-size:14.5px; }
	.msg.bot { background:var(--card); border:1px solid var(--line); align-self:flex-start; border-bottom-left-radius:4px; }
	.msg.me { background:var(--user); align-self:flex-end; border-bottom-right-radius:4px; }
	.msg a { color:var(--brand-d); }
	.typing { align-self:flex-start; color:var(--muted); font-size:13px; padding:6px 4px; }
	.dot { display:inline-block; animation:blink 1.4s infinite both; } .dot:nth-child(2){animation-delay:.2s} .dot:nth-child(3){animation-delay:.4s}
	@keyframes blink { 0%,80%,100%{opacity:.2} 40%{opacity:1} }
	form.bar { display:flex; gap:8px; padding:12px 16px 18px; background:var(--bg); border-top:1px solid var(--line); }
	form.bar input { flex:1; padding:12px 14px; border:1px solid var(--line); border-radius:10px; font-size:15px; background:#fff; }
	form.bar button { padding:0 18px; border:0; background:var(--brand); color:#fff; border-radius:10px; font-size:15px; cursor:pointer; }
	form.bar button:disabled { opacity:.5; cursor:default; }
	.hint { text-align:center; font-size:11px; color:var(--muted); padding:0 0 8px; }
</style>
</head>
<body>
<div class="wrap">
	<header>
		<div class="ico">🐱</div>
		<div>
			<h1><?= h($cfg['widget_title']) ?></h1>
			<div class="sub">学習用ローカル環境 (ezbot-local)</div>
		</div>
		<a class="admin" href="/admin">⚙ 管理</a>
	</header>

	<div id="log"></div>

	<form class="bar" id="bar" autocomplete="off">
		<input id="text" placeholder="質問を入力…" autofocus>
		<button id="send" type="submit">送信</button>
	</form>
	<div class="hint">Enter で送信 ／ 回答は知識ベースとAI (Claude + Voyage) によります</div>
</div>

<script>
const GREETING = <?= json_encode($cfg['widget_greeting'], JSON_UNESCAPED_UNICODE) ?>;
const logEl = document.getElementById('log');
const form  = document.getElementById('bar');
const input = document.getElementById('text');
const sendBtn = document.getElementById('send');

// セッションID（localStorage に保持＝会話を継続）
let sid = localStorage.getItem('ezbot_sid');
if (!sid) { sid = 'sess-' + Math.random().toString(36).slice(2) + Date.now().toString(36); localStorage.setItem('ezbot_sid', sid); }

let history = [];        // {role, content}
let awaitingEmail = false;
let lastQuestion = '';

function escapeHtml(s){ return s.replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }
// URL をリンク化しつつ他はエスケープ
function linkify(s){
	return escapeHtml(s).replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener">$1</a>');
}
function addMsg(text, who){
	const d = document.createElement('div');
	d.className = 'msg ' + (who === 'me' ? 'me' : 'bot');
	d.innerHTML = linkify(text);
	logEl.appendChild(d);
	logEl.scrollTop = logEl.scrollHeight;
	return d;
}
function typing(on){
	let t = document.getElementById('typing');
	if (on){
		if (!t){ t = document.createElement('div'); t.id='typing'; t.className='typing';
			t.innerHTML = 'ノビにゃんが考え中 <span class="dot">●</span><span class="dot">●</span><span class="dot">●</span>';
			logEl.appendChild(t); logEl.scrollTop = logEl.scrollHeight; }
	} else if (t){ t.remove(); }
}

addMsg(GREETING, 'bot');

form.addEventListener('submit', async (e) => {
	e.preventDefault();
	const text = input.value.trim();
	if (!text) return;
	addMsg(text, 'me');
	input.value = '';
	sendBtn.disabled = true;
	typing(true);

	try {
		if (awaitingEmail){
			// メール取得フロー
			const r = await fetch('/api/email', { method:'POST', headers:{'Content-Type':'application/json'},
				body: JSON.stringify({ email:text, session_id:sid, question:lastQuestion }) });
			const j = await r.json();
			typing(false);
			addMsg(j.reply, 'bot');
			if (j.ok) awaitingEmail = false;   // 成功したら通常モードに戻る
			return;
		}

		lastQuestion = text;
		history.push({ role:'user', content:text });
		const r = await fetch('/api/chat', { method:'POST', headers:{'Content-Type':'application/json'},
			body: JSON.stringify({ message:text, history:history.slice(0,-1), session_id:sid }) });
		const j = await r.json();
		typing(false);
		addMsg(j.reply, 'bot');
		history.push({ role:'assistant', content:j.reply });
		if (j.ask_email) awaitingEmail = true;   // 「メールを教えて」モードへ
	} catch(err){
		typing(false);
		addMsg('通信エラーが発生しました。サーバーが起動しているか確認してください。', 'bot');
	} finally {
		sendBtn.disabled = false;
		input.focus();
	}
});
</script>
</body>
</html>
