(() => {
  const list = document.querySelector('#event-list');
  const notice = document.querySelector('#notice');
  const search = document.querySelector('#search');
  const sort = document.querySelector('#sort');
  const API = window.EVENTEASE_API || 'http://localhost/EventEase/backend/api/v1';
  let debounce;
  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, c => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  }[c]));
  async function loadEvents(q='') {
    notice.textContent = 'Loading events…';
    list.replaceChildren();
    try {
      const url = new URL(`${API}/events`);
      url.searchParams.set('page','1'); url.searchParams.set('limit','12');
      if (q.trim()) url.searchParams.set('q',q.trim());
      const [sortBy, direction] = (sort?.value || 'event_date:ASC').split(':');
      url.searchParams.set('sort', sortBy); url.searchParams.set('direction', direction);
      const response = await fetch(url);
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.error?.message || 'Could not load events.');
      if (!result.data.length) { notice.textContent = 'No events found. Try another search.'; return; }
      notice.textContent = `${result.data.length} event(s) shown`;
      list.innerHTML = result.data.map(e => `
        <article class="event-card">
          <div class="category">${escapeHtml(e.category)}</div>
          <h3>${escapeHtml(e.title)}</h3>
          <p class="muted">${escapeHtml(e.venue_name || 'Venue to be announced')}</p>
          <p class="muted">${escapeHtml(new Date(e.event_date.replace(' ','T')).toLocaleString())}</p>
          <p>${escapeHtml(e.description || 'Discover this event with EventEase.')}</p>
          <div class="price">RM ${Number(e.ticket_price).toFixed(2)} <span class="muted">/ ticket</span></div>
          <p class="muted">${Number(e.tickets_available ?? 0)} ticket(s) available</p>
          ${sessionStorage.getItem('eventease_token') ? `<button class="button" type="button" data-book="${Number(e.event_id)}" data-title="${escapeHtml(e.title)}">Book ticket</button>` : `<a class="button" href="login.html">Login to book</a>`}
        </article>`).join('');
    } catch (err) {
      notice.textContent = `${err.message} Check that Apache, MySQL and the API are running.`;
    }
  }
  list.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-book]');
    if (!button) return;
    const token = sessionStorage.getItem('eventease_token');
    if (!token) {
      location.href = 'login.html';
      return;
    }
    const rawQuantity = prompt(`How many tickets for ${button.dataset.title}? (1–10)`, '1');
    if (rawQuantity === null) return;
    const quantity = Number(rawQuantity);
    if (!Number.isInteger(quantity) || quantity < 1 || quantity > 10) {
      notice.textContent = 'Please enter a whole number from 1 to 10.';
      return;
    }
    button.disabled = true;
    notice.textContent = 'Creating your booking…';
    try {
      const response = await fetch(`${API}/bookings`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify({ event_id: Number(button.dataset.book), quantity })
      });
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.error?.message || 'Booking failed.');
      notice.textContent = `Booking created! Reference: ${result.data.booking_reference}. Total: RM ${Number(result.data.total_amount).toFixed(2)}. Status: ${result.data.booking_status}.`;
      const go = document.createElement('a');
      go.href = 'my-bookings.html';
      go.className = 'button';
      go.textContent = 'View my bookings';
      notice.append(' ');
      notice.append(go);
    } catch (error) {
      notice.textContent = error.message;
    } finally {
      button.disabled = false;
    }
  });

  search.addEventListener('input', () => {
    clearTimeout(debounce); debounce = setTimeout(() => loadEvents(search.value), 300);
  });
  sort?.addEventListener('change', () => loadEvents(search.value));
  loadEvents();
})();
