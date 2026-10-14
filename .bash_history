sudo apt update && sudo apt install openvpn -y
sudo openvpn --remote leadstart.guide --dev tun --proto udp
sudo openvpn --remote leadstart.guide --dev tun --proto udp --client --auth-user-pass --comp-lzo
sudo apt update
sudo apt install subversion -y
svn --version
mk dir plugins
mkdir plugins
svn https://plugins.svn.wordpress.org/cross-site-sso-for-openid-connect/assets/
svn co https://plugins.svn.wordpress.org/cross-site-sso-for-openid-connect/assets/
A plugins/trunk
cd plugins
cd ..
$ svn co https://plugins.svn.wordpress.org/cross-site-sso-for-openid-connect/assets/ plugins
> A plugins/trunk
> A plugins/branches
> A plugins/tags
rm -rf plugins assets
svn co https://plugins.svn.wordpress.org/cross-site-sso-for-openid-connect/ plugins
cd plugins
ls
svn add trunk/* assets/*
svn cp trunk tags/1.0.0
svn ci -m "Launching first official version 1.0.0"
svn revert -R .
rm -rf trunk/* tags/*
svn copy trunk tags/1.0.0
svn add trunk/* tags/* --force
svn stat
svn ci -m "Launching first official version 1.0.0"
exit;
