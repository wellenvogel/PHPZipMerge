#! /bin/sh
cd `dirname $0` || exit 1
php composer.phar install || exit 1
version="$1"
if [ "$version" = "" ] ; then
  version=`date '+%Y%m%d'`
fi

zip -r bundle$version . -x tests/\* -x .git/\* -x .idea/\* -x \*.zip
