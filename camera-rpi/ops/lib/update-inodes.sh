#!/usr/bin/env bash
# Keep replaced root-filesystem inodes named until all old readers have exited.
# A byte-copy backup alone cannot prevent remount-ro EBUSY after atomic rename.
# Call under the updater's operation lock, BEFORE rename/unlink, with a private
# backup directory on /. Keep these links with the backup; never delete them
# while old services still run. Immutable byte-copy backups remain separate.
cu_preserve_inode() {
  local target=$1 store=$2 entry target_device root_device store_device
  [[ ! -L $target ]] || return 1
  [[ -e $target ]] || return 0
  [[ -f $target ]] || return 1
  # Volatile runtime snapshots and separate boot filesystems do not hold root
  # inodes. Their update/remount policy remains the caller's responsibility.
  target_device=$(stat -c %d -- "$target") || return 1
  root_device=$(stat -c %d /) || return 1
  [[ $target_device == "$root_device" ]] || return 0
  [[ -d $store && ! -L $store ]] || return 1
  store_device=$(stat -c %d -- "$store") || return 1
  [[ $store_device == "$root_device" ]] || return 1
  entry=$(mktemp -d "$store/inode.XXXXXXXX") || return 1
  if ! ln -- "$target" "$entry/file"; then rmdir -- "$entry"; return 1; fi
  [[ $target -ef $entry/file ]] || return 1
}
