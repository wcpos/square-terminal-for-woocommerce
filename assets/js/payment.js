(function () {
  const log = (message) => {
    const list = document.getElementById('sqtwc-payment-log');
    if (!list) return;
    const item = document.createElement('li');
    item.textContent = new Date().toISOString() + ' ' + message;
    list.appendChild(item);
  };
  document.addEventListener('click', function (event) {
    if (event.target && event.target.id === 'sqtwc-start-payment') log('Start Payment requested');
    if (event.target && event.target.id === 'sqtwc-cancel-payment') log('Cancel Payment requested');
  });
  window.sqtwcPaymentLogError = function (error) { log('error: ' + String(error && error.message ? error.message : error)); };
}());
