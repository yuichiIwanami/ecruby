'use strict';

var app = angular.module('main', ['ngTable']).
    controller('ordersDetail', function ($scope) {
        $scope.products = [
            {id: "001", name: "book", price: 1000, quantity: 1, tax:80}
        ];
//        $scope.users = [
//            {name: "Moroni", age: 50},
//            {name: "Tiancum", age: 43},
//            {name: "Jacob", age: 27},
//            {name: "Nephi", age: 29},
//            {name: "Enos", age: 34}
//        ];
    });