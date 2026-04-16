const getArtboardForWidth = (settings, width) => {
  if (!settings.dynamic_artboards) return null;
  if (width < 768 && settings.artboard_mobile) return settings.artboard_mobile;
  if (width < 1024 && settings.artboard_tablet) return settings.artboard_tablet;
  if (settings.artboard_desktop) return settings.artboard_desktop;
  return null;
};

const createRiveInstance = (canvas, url, settings, assetsMapping, artboard) => {
  return new rive.Rive({
    src: url,
    canvas,
    autoplay: true,
    ...(artboard ? { artboard } : {}),
    assetLoader: (asset, bytes) => {
      const mapping = assetsMapping.find((a) => a.assetName === asset.name);
      if (mapping && mapping.assetUrl) {
        fetch(mapping.assetUrl)
          .then((res) => res.arrayBuffer())
          .then((buf) => rive.decodeImage(new Uint8Array(buf)))
          .then((img) => {
            asset.setRenderImage(img);
            img.unref();
          });
        return true;
      }
      return false;
    },
  });
};

document.addEventListener("DOMContentLoaded", () => {
  document.querySelectorAll(".prefix-rive-wrapper canvas").forEach((canvas) => {
    const url = canvas.getAttribute("data-rive-url");
    const settings = JSON.parse(canvas.getAttribute("data-settings") || "{}");
    const assetsMapping = JSON.parse(
      canvas.getAttribute("data-rive-assets") || "[]"
    );

    if (!url) return;

    let currentArtboard = getArtboardForWidth(settings, window.innerWidth);
    let riveInstance = createRiveInstance(
      canvas,
      url,
      settings,
      assetsMapping,
      currentArtboard
    );

    window.addEventListener("resize", () => {
      const newArtboard = getArtboardForWidth(settings, window.innerWidth);

      if (newArtboard && newArtboard !== currentArtboard) {
        console.log("Switching artboard:", currentArtboard, "→", newArtboard);

        riveInstance.stop();
        riveInstance.cleanup?.();

        riveInstance = createRiveInstance(
          canvas,
          url,
          settings,
          assetsMapping,
          newArtboard
        );
        currentArtboard = newArtboard;
      }
    });
  });
});
