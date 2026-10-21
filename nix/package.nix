{
  perSystem =
    {
      inputs',
      lib,
      pkgs,
      ...
    }:
    {
      packages.default = pkgs.php84.buildComposerProject2 {
        pname = "laravel-stoli";
        version = "0.0.0";
        composer = inputs'.maestro.packages.maestro;

        src = lib.fileset.toSource {
          root = ../.;
          fileset = lib.fileset.difference (lib.fileset.gitTracked ../.) (
            lib.fileset.unions [
              ../flake.nix
              ../flake.lock
              ../nix
            ]
          );
        };

        vendorHash = "sha256-aTosjY4/mz/6UOha08umxo/oP6BasT7O1O4AZ6U6vq0=";
      };
    };
}
