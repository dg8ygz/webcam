(() => {
  const dropdownButtons = document.querySelectorAll('[data-dropdown-toggle]');
  if (!dropdownButtons.length) {
    return;
  }

  const dropdowns = [];
  dropdownButtons.forEach((button) => {
    const wrapper = button.closest('.dropdown');
    if (!wrapper) {
      return;
    }
    dropdowns.push(wrapper);
    button.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      const isOpen = wrapper.classList.contains('is-open');
      dropdowns.forEach((item) => item.classList.remove('is-open'));
      if (!isOpen) {
        wrapper.classList.add('is-open');
      }
    });
  });

  document.addEventListener('click', () => {
    dropdowns.forEach((item) => item.classList.remove('is-open'));
  });
})();
