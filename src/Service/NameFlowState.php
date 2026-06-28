<?php

namespace App\Service;

enum NameFlowState: string
{
    case SEARCH = 'search';
    case CREATE = 'create';
    case UPDATE = 'update';
    case MATCH = 'match';
}
