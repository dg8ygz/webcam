/* UI convenience only; root repeats exclusivity checks before every save.
 * A manipulated duplicate stays visible and gets an error, not a silent reset.
 */
'use strict';
document.querySelectorAll('[data-gpio-form]').forEach(form => {
  const selects = [...form.querySelectorAll('[data-gpio-select]')];
  function update() {
    for (const select of selects) {
      const others = selects.filter(other => other !== select).map(other => other.value).filter(Boolean);
      const conflict = select.value !== '' && others.includes(select.value);
      select.setCustomValidity(conflict ? 'GPIO ' + select.value + ' ist bereits in Benutzung.' : '');
      for (const option of select.options) {
        const used = option.value !== '' && others.includes(option.value);
        option.disabled = used && option.value !== select.value;
        option.hidden = option.disabled;
      }
    }
  }
  form.addEventListener('change', update);
  form.addEventListener('submit', event => {
    update();
    if (!form.reportValidity()) event.preventDefault();
  });
  update();
});
