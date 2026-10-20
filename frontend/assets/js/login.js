(() => {
  const notice = document.querySelector('#notice');
  const reason = new URLSearchParams(location.search).get('reason');
  if (reason === 'timeout') notice.textContent = 'You were logged out after 15 minutes of inactivity. Please log in again.';
  else if (reason === 'expired') notice.textContent = 'Your session expired. Please log in again.';
  else if (reason === 'logout') notice.textContent = 'You have been logged out.';
})();

document.querySelector('#login-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  const notice = document.querySelector('#notice');
  const form = new FormData(event.currentTarget);
  notice.textContent = 'Signing in…';
  try {
    const response = await fetch(`${window.EVENTEASE_API}/auth/login`, {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({email:form.get('email'), password:form.get('password')})
    });
    const result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.error?.message || 'Login failed.');
    // For a classroom prototype only. Prefer HttpOnly secure cookies for production applications.
    sessionStorage.setItem('eventease_token', result.data.access_token);
    sessionStorage.setItem('eventease_user', JSON.stringify(result.data.user));
    notice.textContent = 'Login successful. Redirecting…';
    location.href = result.data.user.role === 'CUSTOMER' ? 'my-bookings.html' : 'index.html';
  } catch (err) { notice.textContent = err.message; }
});
