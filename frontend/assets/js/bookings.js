(() => {
  const notice = document.querySelector('#notice');
  const list = document.querySelector('#booking-list');
  const token = sessionStorage.getItem('eventease_token');
  const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  if (!token) { notice.textContent = 'Please log in to view your bookings.'; return; }
  async function load() {
    notice.textContent = 'Loading bookings…';
    try {
      const r = await fetch(`${window.EVENTEASE_API}/bookings`, {headers:{Authorization:`Bearer ${token}`}});
      const d = await r.json();
      if (!r.ok || !d.success) throw new Error(d.error?.message || 'Could not load bookings.');
      if (!d.data.length) { notice.textContent = 'You do not have any bookings yet.'; return; }
      notice.textContent = '';
      list.innerHTML = d.data.map(b => `<article class="booking-card">
        <div><h3>${esc(b.title)}</h3><p class="muted">Reference: ${esc(b.booking_reference)} · Quantity: ${Number(b.quantity)}</p>
        <p class="muted">${esc(b.event_date)}</p><p class="muted">Payment: ${esc(b.payment_status || 'N/A')}</p></div>
        <div><p class="price">RM ${Number(b.total_amount).toFixed(2)}</p><span class="status">${esc(b.booking_status)}</span>
        ${b.booking_status === 'CONFIRMED' ? `<button class="button" data-qr="${Number(b.booking_id)}">Show ticket QR</button>` : ''}
        ${b.booking_status !== 'CANCELLED' ? `<button class="button" data-cancel="${Number(b.booking_id)}">Cancel booking</button>` : ''}</div>
      </article>`).join('');
    } catch (e) { notice.textContent = e.message; }
  }
  list.addEventListener('click', async e => {
    const qrButton=e.target.closest('[data-qr]');
    if (qrButton) { qrButton.disabled=true; try { const r=await fetch(`${window.EVENTEASE_API}/bookings/${qrButton.dataset.qr}/qr`,{headers:{Authorization:`Bearer ${token}`}}); const d=await r.json(); if(!r.ok||!d.success) throw new Error(d.error?.message||'Could not create QR ticket.'); const img=document.createElement('img'); img.alt='Ticket QR code'; img.width=180; img.src=`data:image/png;base64,${d.data.image_base64}`; qrButton.replaceWith(img); } catch(err) { notice.textContent=err.message; qrButton.disabled=false; } return; }
    const button = e.target.closest('[data-cancel]');
    if (!button || !confirm('Cancel this booking?')) return;
    button.disabled = true;
    try {
      const r = await fetch(`${window.EVENTEASE_API}/bookings/${button.dataset.cancel}/cancel`, {
        method:'PATCH', headers:{Authorization:`Bearer ${token}`}
      });
      const d = await r.json();
      if (!r.ok || !d.success) throw new Error(d.error?.message || 'Cancellation failed.');
      await load();
    } catch (err) { notice.textContent = err.message; button.disabled = false; }
  });
  load();
})();
