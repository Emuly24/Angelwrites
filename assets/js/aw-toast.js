// Small, non-blocking message at the bottom of the screen (Q16; same as the Bible reader's, Q9).
// awToast(message, type) with type 'ok' (default), 'info' or 'error'. The message is set as text.
function awToast(msg, type) {
    var box = document.getElementById('awToast');
    if (!box) {
        box = document.createElement('div');
        box.id = 'awToast';
        box.setAttribute('role', 'status');
        box.style.cssText = 'position:fixed;left:50%;bottom:calc(20px + var(--cookie-bar-h, 0px));transform:translateX(-50%);z-index:3000;max-width:92vw;padding:12px 18px;border-radius:10px;box-shadow:0 6px 24px rgba(0,0,0,0.18);font-size:0.95rem;color:#fff;transition:opacity 0.3s;';
        document.body.appendChild(box);
    }
    box.style.background = type === 'error' ? '#b03a3a' : (type === 'info' ? '#6b5a5a' : '#3f7d4f');
    box.textContent = msg;
    box.style.opacity = '1';
    clearTimeout(box._t);
    box._t = setTimeout(function () { box.style.opacity = '0'; }, 3500);
}
