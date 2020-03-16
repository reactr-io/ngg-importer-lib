<?php

namespace ReactrIO\NggImporter;

class E_FileImporter extends \RuntimeException
{
    // Use this instead of the constructor.
    //
    // Unfortunately, PHP won't allow us to define the constructor as private, as RuntimeException's
    // constructor is public
    public static function create($message, $context=array(), $code=0, $previous=NULL)
    {
        if (!is_array($context)) $context= array();
        $context['msg'] = $message;

        $klass = get_called_class();

        return new $klass(json_encode($context), $code, $previous);
    }

    function getContext()
    {
        return json_decode(parent::getMessage(), TRUE);
    }

    function getErrMsg()
    {
        return $this->getContext()['msg'];
    }
}