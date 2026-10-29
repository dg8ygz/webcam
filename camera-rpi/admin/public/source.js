"use strict";
document.querySelectorAll(".source-form").forEach(form => {
  const source = form.elements.capture_backend;
  const device = form.elements.video_device;
  const devices = form.querySelector("[data-source-devices]");
  const refresh = () => {
    form.querySelectorAll("[data-source-fields]").forEach(group => {
      group.hidden = group.dataset.sourceFields !== source.value;
    });
    form.elements.rtsp_url.required = source.value === "rtsp";
    ["video_device", "video_width", "video_height", "video_warmup_frames", "video_input_fps"].forEach(name => {
      form.elements[name].required = source.value === "v4l2";
    });
  };
  source.addEventListener("change", refresh);
  devices.addEventListener("change", () => {
    if (devices.value) device.value = devices.value;
    else device.focus();
  });
  device.addEventListener("input", () => {
    devices.value = Array.from(devices.options).some(option => option.value === device.value) ? device.value : "";
  });
  refresh();
});
