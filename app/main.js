const clocks = document.querySelectorAll('[data-timezone]');

function updateClocks() {
  const now = new Date();

  clocks.forEach((clock) => {
    clock.textContent = new Intl.DateTimeFormat('ru-RU', {
      timeZone: clock.dataset.timezone,
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      hour12: false
    }).format(now);
  });
}

if (clocks.length) {
  updateClocks();
  setInterval(updateClocks, 1000);
}
