(() => {
  const TOKEN_KEY = 'eventease_token';
  const USER_KEY = 'eventease_user';
  const IDLE_TIMEOUT_MS = 15 * 60 * 1000; // 15 minutes without activity
  let idleTimer = null;
  let loggingOut = false;

  const getToken = () => sessionStorage.getItem(TOKEN_KEY);
  const getUser = () => {
    try { return JSON.parse(sessionStorage.getItem(USER_KEY) || 'null'); }
    catch { return null; }
  };

  function updateNavigation() {
    const nav = document.querySelector('.topbar nav');
    if (!nav) return;
    const user = getUser();
    const links = [
      '<a href="index.html#events">Explore</a>',
      user ? '<a href="my-bookings.html">My bookings</a>' : '<a href="login.html">Login</a>'
    ];
    if (user) {
      if (['ADMIN','ORGANISER'].includes(user.role)) links.push('<a href="manage-events.html">Manage events</a>');
      const name = String(user.name || 'Account').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
      links.push(`<span class="nav-user">${name}</span>`);
      links.push('<a href="#logout" id="logout-link">Logout</a>');
    }
    nav.innerHTML = links.join('');
    const logoutLink = document.querySelector('#logout-link');
    if (logoutLink) logoutLink.addEventListener('click', async e => {
      e.preventDefault();
      await logout('manual');
    });
  }

  async function logout(reason = 'manual') {
    if (loggingOut) return;
    loggingOut = true;
    const token = getToken();
    if (token && window.EVENTEASE_API) {
      try {
        await fetch(`${window.EVENTEASE_API}/auth/logout`, {
          method: 'POST', headers: { Authorization: `Bearer ${token}` },
          keepalive: true
        });
      } catch (_) { /* Local credentials are still cleared if the API is offline. */ }
    }
    sessionStorage.removeItem(TOKEN_KEY);
    sessionStorage.removeItem(USER_KEY);
    if (idleTimer) clearTimeout(idleTimer);
    const target = reason === 'timeout' ? 'login.html?reason=timeout' : 'login.html?reason=logout';
    window.location.href = target;
  }

  function resetIdleTimer() {
    if (!getToken()) return;
    if (idleTimer) clearTimeout(idleTimer);
    idleTimer = setTimeout(() => logout('timeout'), IDLE_TIMEOUT_MS);
  }

  // Any API 401 while signed in means the token has expired, been revoked, or is invalid.
  // Clear the local session and send the user to login instead of leaving a broken page.
  const originalFetch = window.fetch.bind(window);
  window.fetch = async (...args) => {
    const response = await originalFetch(...args);
    const token = getToken();
    const input = args[0];
    const url = typeof input === 'string' ? input : (input && input.url) || '';
    if (token && response.status === 401 && window.EVENTEASE_API && url.includes(window.EVENTEASE_API)) {
      sessionStorage.removeItem(TOKEN_KEY);
      sessionStorage.removeItem(USER_KEY);
      if (idleTimer) clearTimeout(idleTimer);
      if (!location.pathname.endsWith('/login.html')) location.href = 'login.html?reason=expired';
    }
    return response;
  };

  document.addEventListener('DOMContentLoaded', () => {
    updateNavigation();
    if (getToken()) {
      ['click', 'keydown', 'mousemove', 'scroll', 'touchstart'].forEach(type => {
        document.addEventListener(type, resetIdleTimer, { passive: true });
      });
      resetIdleTimer();
    }
  });

  window.EventEaseAuth = { logout, resetIdleTimer, getUser, getToken };
})();
