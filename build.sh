#! /bin/sh
cd `dirname $0` || exit 1
php composer.phar install || exit 1
version="$1"
if [ "$version" = "" ] ; then
  version=`date '+%Y%m%d'`
fi
rm -f bundle$version.zip
zip -r bundle$version . -x tests/\* -x .git/\* -x .idea/\* -x \*.zip -x .github/\* -x .gitignore -x .gitattributes -x composer.phar -x composer.json -x build.sh